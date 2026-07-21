<?php
/**
 * Plugin Name: Frete Grátis por Classe
 * Description: Oferece frete grátis com base em classe prioritária, valor mínimo específico e integração completa com Flatsome.
 * Version: 1.5.1
 * Author: Rafael Moreno
 * Text Domain: frete-gratis-por-classe
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) exit;

class Frete_Gratis_Por_Classe {
    const PRIORIDADE_CLASSES = ['volumetric-weight', 'brasil'];
    const LIMITES_ESPECIAIS = [
        'volumetric-weight' => 600.00,
        // Adicione outras classes aqui se necessário
    ];

    private static $metodos_cache = [];
    
    public static function init() {
        add_filter('woocommerce_shipping_instance_form_fields_free_shipping', [__CLASS__, 'add_required_shipping_class_field']);
        add_filter('woocommerce_package_rates', [__CLASS__, 'filter_package_rates'], 100, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', [__CLASS__, 'label_gratis'], 10, 2);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'show_notice']);
        // Recalcula o aviso a cada atualização AJAX do checkout (update_order_review)
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'show_notice']);

        // Integração Flatsome
        add_filter('flatsome_shipping_free_shipping_threshold', [__CLASS__, 'flatsome_free_shipping_threshold']);

        // Oculta a barra .ux-free-shipping quando frete grátis for impossível (compatível com AJAX)
        add_action('wp_footer', [__CLASS__, 'output_hide_bar_style']);
        add_filter('woocommerce_add_to_cart_fragments', [__CLASS__, 'hide_bar_fragment']);
        add_filter('woocommerce_update_order_review_fragments', [__CLASS__, 'hide_bar_fragment']);
    }

    // Campo no admin (Frete grátis)
    public static function add_required_shipping_class_field($fields) {
        if (!class_exists('WC_Shipping')) return $fields;

        $shipping_classes = WC()->shipping()->get_shipping_classes();
        $options = ['' => __('Todas as classes', 'frete-gratis-por-classe')];
        foreach ($shipping_classes as $class) {
            $options[$class->slug] = $class->name;
        }

        $fields['required_shipping_class'] = [
            'title' => __('Classe de Entrega Requerida', 'frete-gratis-por-classe'),
            'type' => 'select',
            'description' => __('Classe exigida para ativar esse frete grátis.', 'frete-gratis-por-classe'),
            'default' => '',
            'desc_tip' => true,
            'options' => $options,
        ];

        return $fields;
    }

    // Filtra métodos conforme prioridade e valores
    public static function filter_package_rates($rates, $package) {
        if (is_admin() && !wp_doing_ajax()) return $rates;

        $classes_no_carrinho = self::get_cart_shipping_classes($package);
        $classe_prioritaria = self::get_prioritaria($classes_no_carrinho);
        if (!$classe_prioritaria) return $rates;

        $subtotal_prioritaria = self::subtotal_by_class($package, $classe_prioritaria);
        $package_total = self::get_package_total($package);

        // Verifica limite da classe, se houver
        if (isset(self::LIMITES_ESPECIAIS[$classe_prioritaria]) && $subtotal_prioritaria > self::LIMITES_ESPECIAIS[$classe_prioritaria]) {
            foreach ($rates as $rate_id => $rate) {
                if ($rate->method_id === 'free_shipping') unset($rates[$rate_id]);
            }

            return $rates;
        }

        // Remove métodos que não correspondem à classe prioritária
        $encontrou_metodo_prioritario = false;

        foreach ($rates as $rate_id => $rate) {
            if ($rate->method_id !== 'free_shipping') continue;

            $method = self::get_shipping_method_instance($rate, $package);
            if (!$method) continue;

            $required_class = trim($method->get_option('required_shipping_class', ''));

            if ($required_class !== $classe_prioritaria) {
                unset($rates[$rate_id]);
            } else {
                $min_amount = floatval($method->get_option('min_amount', 0));
                if ($package_total < $min_amount) {
                    unset($rates[$rate_id]);
                } else {
                    $encontrou_metodo_prioritario = true;
                }
            }
        }

        // Remove qualquer frete grátis se nenhum método correspondente for elegível
        if (!$encontrou_metodo_prioritario) {
            foreach ($rates as $rate_id => $rate) {
                if ($rate->method_id === 'free_shipping') unset($rates[$rate_id]);
            }
        }

        return $rates;
    }

    // Adiciona "GRÁTIS" no rótulo de frete
    public static function label_gratis($label, $method) {
        if ($method->cost == 0) {
            $label .= ': <strong style="color: #50b848;">GRÁTIS</strong>';
        }
        return $label;
    }

    // Calcula o aviso diretamente do estado do carrinho (independe do cache de fretes)
    public static function get_limite_notice() {
        if (!function_exists('WC') || !WC()->cart) return '';

        $package = WC()->cart->get_shipping_packages()[0] ?? null;
        if (!$package) return '';

        $classes_no_carrinho = self::get_cart_shipping_classes($package);
        $classe_prioritaria = self::get_prioritaria($classes_no_carrinho);
        if (!$classe_prioritaria || !isset(self::LIMITES_ESPECIAIS[$classe_prioritaria])) return '';

        $subtotal_prioritaria = self::subtotal_by_class($package, $classe_prioritaria);
        if ($subtotal_prioritaria <= self::LIMITES_ESPECIAIS[$classe_prioritaria]) return '';

        return sprintf(
            'Para utilizar o frete grátis, o valor dos produtos da classe "%s" não pode ultrapassar R$ %s. Atualmente: R$ %s.',
            esc_html(self::get_class_name($classe_prioritaria)),
            number_format(self::LIMITES_ESPECIAIS[$classe_prioritaria], 2, ',', '.'),
            number_format($subtotal_prioritaria, 2, ',', '.')
        );
    }

    // Exibe aviso no carrinho ou checkout (roda em todo render, inclusive AJAX)
    public static function show_notice() {
        // Nunca adiciona o aviso durante a finalização do pedido:
        // um notice do tipo "error" nesse momento bloquearia o checkout.
        if (self::is_checkout_being_processed()) return;

        $notice = self::get_limite_notice();
        if ($notice && !wc_has_notice($notice, 'error')) {
            wc_add_notice($notice, 'error');
        }
    }

    // Detecta se o pedido está sendo finalizado (submit do checkout)
    public static function is_checkout_being_processed() {
        if (isset($_POST['woocommerce-process-checkout-nonce']) || isset($_POST['woocommerce_checkout_place_order'])) {
            return true;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax() && isset($_GET['wc-ajax']) && $_GET['wc-ajax'] === 'checkout') {
            return true;
        }
        if (defined('WOOCOMMERCE_CHECKOUT') && WOOCOMMERCE_CHECKOUT && isset($_POST['payment_method'])) {
            return true;
        }
        return false;
    }

    // Integração com a barra de frete grátis do Flatsome
    public static function flatsome_free_shipping_threshold($threshold) {
        $package = WC()->cart->get_shipping_packages()[0] ?? null;
        if (!$package) return $threshold;

        $classes_no_carrinho = self::get_cart_shipping_classes($package);
        $classe_prioritaria = self::get_prioritaria($classes_no_carrinho);
        if (!$classe_prioritaria) return $threshold;

        $zone = wc_get_shipping_zone($package);
        $methods = $zone->get_shipping_methods(true);
        $package_total = self::get_package_total($package);

        foreach ($methods as $method) {
            if ($method->id !== 'free_shipping') continue;

            $required_class = trim($method->get_option('required_shipping_class', ''));
            $min_amount = floatval($method->get_option('min_amount', 0));

            if ($required_class === $classe_prioritaria && $package_total < $min_amount) {
                return $min_amount;
            }
        }

        return $threshold;
    }

    // Determina se a barra de frete grátis deve ser ocultada (frete grátis impossível)
    public static function should_hide_free_shipping_bar() {
        if (!function_exists('WC') || !WC()->cart) return false;

        $package = WC()->cart->get_shipping_packages()[0] ?? null;
        if (!$package) return false;

        $classes_no_carrinho = self::get_cart_shipping_classes($package);
        $classe_prioritaria = self::get_prioritaria($classes_no_carrinho);
        if (!$classe_prioritaria) return false;

        // Limite especial ultrapassado: frete grátis impossível, oculta a barra
        if (isset(self::LIMITES_ESPECIAIS[$classe_prioritaria])) {
            $subtotal_prioritaria = self::subtotal_by_class($package, $classe_prioritaria);
            if ($subtotal_prioritaria > self::LIMITES_ESPECIAIS[$classe_prioritaria]) {
                return true;
            }
        }

        // Sem nenhum método de frete grátis configurado para a classe prioritária: impossível
        $zone = wc_get_shipping_zone($package);
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->id !== 'free_shipping') continue;
            $required_class = trim($method->get_option('required_shipping_class', ''));
            if ($required_class === $classe_prioritaria) {
                return false; // Existe método elegível, mantém a barra visível
            }
        }

        return true;
    }

    // Gera o <style> marcador que controla a visibilidade da barra
    public static function get_hide_bar_style() {
        $css = self::should_hide_free_shipping_bar()
            ? '.ux-free-shipping{display:none !important;}'
            : '';
        return '<style id="fgpc-hide-free-shipping">' . $css . '</style>';
    }

    // Imprime o marcador no rodapé (carregamento inicial da página)
    // e o script que sincroniza os fragments nas atualizações AJAX do carrinho/checkout
    public static function output_hide_bar_style() {
        echo self::get_hide_bar_style();
        ?>
        <script>
        jQuery(function($){
            // No checkout o marcador é atualizado nativamente via update_order_review_fragments.
            // Aqui cobrimos apenas a página do carrinho, cujo AJAX não passa pelos fragments.
            var fgpcRefreshing = false;
            $(document.body).on('updated_wc_div updated_cart_totals', function(){
                if (fgpcRefreshing) return;
                fgpcRefreshing = true;
                $(document.body).trigger('wc_fragment_refresh');
                setTimeout(function(){ fgpcRefreshing = false; }, 800);
            });
        });
        </script>
        <?php
    }

    // Atualiza o marcador nas atualizações AJAX do carrinho (cart fragments)
    public static function hide_bar_fragment($fragments) {
        $fragments['#fgpc-hide-free-shipping'] = self::get_hide_bar_style();
        return $fragments;
    }

    // === Métodos auxiliares ===

    public static function get_cart_shipping_classes($package) {
        $classes = [];
        foreach ($package['contents'] as $item) {
            $class = $item['data']->get_shipping_class();
            if ($class) $classes[] = $class;
        }
        return array_unique($classes);
    }

    public static function get_prioritaria($classes) {
        foreach (self::PRIORIDADE_CLASSES as $prioritaria) {
            if (in_array($prioritaria, $classes, true)) return $prioritaria;
        }
        return null;
    }

    public static function subtotal_by_class($package, $class_slug) {
        $subtotal = 0;
        foreach ($package['contents'] as $item) {
            if ($item['data']->get_shipping_class() === $class_slug) {
                $subtotal += $item['line_total'];
            }
        }
        return $subtotal;
    }

    public static function get_package_total($package) {
        $total = 0;
        foreach ($package['contents'] as $item) {
            $total += ($item['line_total'] + $item['line_tax']);
        }
        return $total;
    }

    public static function get_class_name($slug) {
        $term = get_term_by('slug', $slug, 'product_shipping_class');
        return $term ? $term->name : $slug;
    }

    public static function get_shipping_method_instance($rate, $package) {
        $key = $rate->instance_id;
        if (isset(self::$metodos_cache[$key])) return self::$metodos_cache[$key];

        $zone = WC_Shipping_Zones::get_zone_matching_package($package);
        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->instance_id == $rate->instance_id) {
                self::$metodos_cache[$key] = $method;
                return $method;
            }
        }

        return null;
    }
}

Frete_Gratis_Por_Classe::init();



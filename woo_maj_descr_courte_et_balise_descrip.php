<?php
require_once(__DIR__ . '/../wp-load.php');
require_once(__DIR__ . '/../wp-content/plugins/woocommerce/woocommerce.php');

// Constantes et variables globales
define('ANTHROPIC_API_KEY', 'VOTRE CLE ANTHROPIC');
define('SHORT_DESCRIPTION_PROMPT', "Générer une description courte (maximum 100 mots) optimisée pour le SEO pour un produit e-commerce. Répondez uniquement avec le contenu de la description, sans aucune introduction ni explication. Tu peux utiliser des balises HTML strong br ul li dans la description afin d'améliorer la mise en page.
Nom du produit : {product_name} 
Description complète : {product_description}");
define('META_DESCRIPTION_PROMPT', "Générer le contenu de la balise meta description (160 caractères maximum) optimisée pour le SEO pour un produit e-commerce. Répondez uniquement avec le contenu de la balise sans HTML, sans aucune introduction ni explication. 
Nom du produit : {product_name}
Description complète : {product_description}");

// Mode simulation par défaut
$simulation_mode = false;

// Fonction pour logger les messages
function log_message($message) {
    echo $message . "<br>\n";
    error_log($message);
}

function call_anthropic_api($prompt) {
    $url = 'https://api.anthropic.com/v1/messages';

    $data = [
        'model' => 'claude-3-sonnet-20240229',
        'max_tokens' => 300,
        'temperature' => 0.7,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];

    $jsonData = json_encode($data);

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        log_message("Erreur cURL : " . $err);
        return null;
    }

    $responseData = json_decode($response, true);

    if (isset($responseData['error'])) {
        log_message("Erreur API Anthropic : " . $responseData['error']['message']);
        return null;
    }

    if (!isset($responseData['content'][0]['text'])) {
        log_message("Erreur : Format de réponse inattendu de l'API.");
        return null;
    }

    return trim($responseData['content'][0]['text']);
}

function update_product_descriptions($product_id) {
    global $simulation_mode;
    
    log_message("--- Début du traitement pour le produit ID: $product_id ---");
    
    $product = wc_get_product($product_id);

    if (!$product) {
        log_message("Erreur : Produit non trouvé.");
        return false;
    }

    $product_name = $product->get_name();
    $product_description = $product->get_description();
    $current_short_description = $product->get_short_description();
    $current_meta_description = get_post_meta($product_id, '_yoast_wpseo_metadesc', true);

    $update_short_description = empty(trim($current_short_description));
    $update_meta_description = empty(trim($current_meta_description));

    if (!$update_short_description && !$update_meta_description) {
        log_message("Aucune mise à jour nécessaire. Les descriptions sont déjà renseignées.");
        return false;
    }

    $updated = false;

    if ($update_short_description) {
        $short_description_prompt = str_replace(
            ['{product_name}', '{product_description}'],
            [$product_name, $product_description],
            SHORT_DESCRIPTION_PROMPT
        );

        $short_description = call_anthropic_api($short_description_prompt);

        if (!$short_description) {
            log_message("Erreur lors de la génération de la description courte.");
            return false;
        }

        // Limiter la description courte à 100 mots
        $short_description_words = explode(' ', $short_description);
        if (count($short_description_words) > 100) {
            $short_description = implode(' ', array_slice($short_description_words, 0, 100));
        }

        if (!$simulation_mode) {
            $product->set_short_description($short_description);
            $product->save();
            $updated = true;
        }
        log_message("Nouvelle description courte générée.");
    }

    if ($update_meta_description) {
        $meta_description_prompt = str_replace(
            ['{product_name}', '{product_description}'],
            [$product_name, $product_description],
            META_DESCRIPTION_PROMPT
        );

        $meta_description = call_anthropic_api($meta_description_prompt);

        if (!$meta_description) {
            log_message("Erreur lors de la génération de la balise meta description.");
            return false;
        }

        // Tronquer la balise meta description à exactement 160 caractères
        $meta_description = substr($meta_description, 0, 160);

        if (!$simulation_mode) {
            update_post_meta($product_id, '_yoast_wpseo_metadesc', $meta_description);
            $updated = true;
        }
        log_message("Nouvelle balise meta description générée.");
    }

    if ($simulation_mode) {
        log_message("Mode simulation : aucune mise à jour effectuée.");
    } else if ($updated) {
        log_message("Mises à jour effectuées avec succès.");
    }

    return $updated;
}

function process_category_products($category_id, $limit = 50) {
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => $limit,
        'tax_query'      => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_id,
            ),
        ),
    );

    $products = new WP_Query($args);
    $updates_count = 0;
    $updated_products = array();

    if ($products->have_posts()) {
        while ($products->have_posts()) {
            $products->the_post();
            $product_id = get_the_ID();
            $product = wc_get_product($product_id);

            $current_short_description = $product->get_short_description();
            $current_meta_description = get_post_meta($product_id, '_yoast_wpseo_metadesc', true);

            if (empty(trim($current_short_description)) || empty(trim($current_meta_description))) {
                log_message("Traitement du produit ID: $product_id");
                $result = update_product_descriptions($product_id);
                if ($result === true) {
                    $updates_count++;
                    $updated_products[] = array(
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'url' => get_permalink($product_id)
                    );
                }
            }
        }
    } else {
        log_message("Aucun produit trouvé dans cette catégorie.");
    }

    wp_reset_postdata();
    return array('count' => $updates_count, 'products' => $updated_products);
}

// Formulaire HTML
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mise à jour des descriptions de produits</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
            form { max-width: 400px; margin: 0 auto; }
            label { display: block; margin-bottom: 5px; }
            input[type="number"] { width: 100%; padding: 8px; margin-bottom: 10px; }
            input[type="submit"] { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; cursor: pointer; }
            input[type="submit"]:hover { background-color: #45a049; }
        </style>
    </head>
    <body>
        <h1>Mise à jour des descriptions de produits</h1>
        <form method="post">
            <label for="category_id">ID de la catégorie :</label>
            <input type="number" id="category_id" name="category_id" required>
            
            <label for="limit">Nombre d'articles à traiter (50 par défaut) :</label>
            <input type="number" id="limit" name="limit" value="50">
            
            <input type="submit" value="Traiter les produits">
        </form>
    </body>
    </html>
    <?php
} else {
    // Traitement du formulaire
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;

    if ($category_id > 0) {
        log_message("Début du traitement pour la catégorie ID: $category_id");
        log_message("Nombre maximum de produits à traiter : $limit");
        $result = process_category_products($category_id, $limit);
        log_message("Fin du traitement pour la catégorie.");
        log_message("Nombre total de mises à jour effectuées : " . $result['count']);
        
        if ($result['count'] > 0) {
            log_message("<h3>Liste des produits mis à jour :</h3>");
            log_message("<ul>");
            foreach ($result['products'] as $product) {
                log_message("<li><a href='" . esc_url($product['url']) . "' target='_blank'>" . esc_html($product['name']) . " (ID: " . $product['id'] . ")</a></li>");
            }
            log_message("</ul>");
        } else {
            log_message("Aucun produit n'a été mis à jour.");
        }
    } else {
        log_message("ID de catégorie invalide.");
    }
}

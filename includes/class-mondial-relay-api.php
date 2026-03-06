<?php
/**
 * Mondial Relay SOAP API Client
 * 
 * Handles communication with Mondial Relay web services:
 * - Search relay points (WSI4_PointRelais_Recherche)
 * - Create shipments (WSI2_CreationExpedition)
 * - Get shipping labels (WSI3_GetEtiquettes)
 * 
 * @see https://api.mondialrelay.com/Web_Services.asmx
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Mondial_Relay_API {

    /**
     * SOAP WSDL endpoint
     */
    const WSDL_URL = 'https://api.mondialrelay.com/Web_Services.asmx?WSDL';

    /**
     * Get API credentials from options
     *
     * @return array|false
     */
    private static function get_credentials() {
        $settings = get_option('directpay_mondial_relay_api', []);

        if (empty($settings['enseigne']) || empty($settings['private_key'])) {
            return false;
        }

        return [
            'enseigne'    => $settings['enseigne'],
            'private_key' => $settings['private_key'],
            'brand_id'    => $settings['brand_id'] ?? '',
        ];
    }

    /**
     * Create a SOAP client
     *
     * @return SoapClient
     * @throws Exception
     */
    private static function get_soap_client() {
        if (!class_exists('SoapClient')) {
            throw new Exception(__('PHP SOAP extension is required for Mondial Relay integration.', 'directpay-go'));
        }

        return new SoapClient(self::WSDL_URL, [
            'trace'              => true,
            'exceptions'         => true,
            'connection_timeout' => 30,
            'default_socket_timeout' => 15,
            'cache_wsdl'         => WSDL_CACHE_BOTH,
        ]);
    }

    /**
     * Generate MD5 security hash
     * Mondial Relay uses MD5( concatenation of all params + private_key )
     *
     * @param array  $params      Ordered parameter values
     * @param string $private_key The merchant private key
     * @return string Uppercase MD5 hash
     */
    private static function generate_security_hash(array $params, string $private_key): string {
        $concat = implode('', $params) . $private_key;
        return strtoupper(md5($concat));
    }

    /**
     * Check if API is configured
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::get_credentials() !== false;
    }

    /**
     * Search for relay points / pickup locations
     *
     * @param string $country  Country code (e.g. FR, BE, ES)
     * @param string $postcode Postal code
     * @param int    $nb_results Number of results (max 30)
     * @return array|WP_Error
     */
    public static function search_relay_points(string $country, string $postcode, int $nb_results = 20) {
        $creds = self::get_credentials();
        if (!$creds) {
            return new WP_Error('not_configured', __('Mondial Relay API is not configured', 'directpay-go'));
        }

        try {
            $client = self::get_soap_client();

            // ALL parameters for WSI4_PointRelais_Recherche in EXACT order from WSDL
            // WSI4 (not WSI3) supports NombreResultats field
            $params = [
                'Enseigne'        => $creds['enseigne'],
                'Pays'            => strtoupper($country),
                'NumPointRelais'  => '',
                'Ville'           => '',
                'CP'              => $postcode,
                'Latitude'        => '',
                'Longitude'       => '',
                'Taille'          => '',
                'Poids'           => '',
                'Action'          => '',
                'DelaiEnvoi'      => '',
                'RayonRecherche'  => '',
                'TypeActivite'    => '',
                'NACE'            => '',
                'NombreResultats' => (string) $nb_results,
            ];

            // Security hash: concat ALL param values (including empty) + private_key
            $params['Security'] = self::generate_security_hash(
                array_values($params),
                $creds['private_key']
            );

            $response = $client->WSI4_PointRelais_Recherche($params);
            $result = $response->WSI4_PointRelais_RechercheResult;

            if (trim($result->STAT) !== '0') {
                $stat_code = trim($result->STAT);
                $stat_desc = self::get_error_description($stat_code);
                error_log('Mondial Relay Search Error - STAT: ' . $stat_code . ' — ' . $stat_desc);
                return new WP_Error(
                    'mr_search_failed',
                    sprintf(__('Mondial Relay error code: %s — %s', 'directpay-go'), $stat_code, $stat_desc),
                    ['status' => 400, 'stat' => $stat_code]
                );
            }

            // Parse relay points
            $points = [];
            if (isset($result->PointsRelais->PointRelais_Details)) {
                $relay_points = $result->PointsRelais->PointRelais_Details;
                // Ensure it's an array even if single result
                if (!is_array($relay_points)) {
                    $relay_points = [$relay_points];
                }

                foreach ($relay_points as $point) {
                    $points[] = [
                        'id'         => trim($point->Num ?? ''),
                        'name'       => trim($point->LgAdr1 ?? ''),
                        'address'    => trim($point->LgAdr3 ?? ''),
                        'city'       => trim($point->Ville ?? ''),
                        'postalCode' => trim($point->CP ?? ''),
                        'country'    => trim($point->Pays ?? ''),
                        'latitude'   => trim($point->Latitude ?? ''),
                        'longitude'  => trim($point->Longitude ?? ''),
                        'type'       => trim($point->TypeActivite ?? ''),
                        'hours'      => self::parse_opening_hours($point),
                    ];
                }
            }

            return $points;

        } catch (SoapFault $e) {
            error_log('Mondial Relay SOAP Fault: ' . $e->getMessage());
            return new WP_Error('soap_fault', $e->getMessage(), ['status' => 500]);
        } catch (Exception $e) {
            error_log('Mondial Relay Error: ' . $e->getMessage());
            return new WP_Error('mr_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Create a shipment (expedition)
     *
     * @param array $shipment_data {
     *     @type string $sender_name       Sender company/name
     *     @type string $sender_address     Sender street address
     *     @type string $sender_city        Sender city
     *     @type string $sender_postcode    Sender postal code
     *     @type string $sender_country     Sender country code
     *     @type string $sender_phone       Sender phone
     *     @type string $sender_email       Sender email
     *     @type string $recipient_name     Recipient full name
     *     @type string $recipient_address  Recipient street address
     *     @type string $recipient_city     Recipient city
     *     @type string $recipient_postcode Recipient postal code
     *     @type string $recipient_country  Recipient country code
     *     @type string $recipient_phone    Recipient phone
     *     @type string $recipient_email    Recipient email
     *     @type string $relay_id           Relay point ID (for relay delivery)
     *     @type string $reference          Order reference
     *     @type string $product_name       Product description
     *     @type int    $weight             Weight in grams
     *     @type string $delivery_mode      MR delivery mode (24R = relay, HOM = home)
     *     @type int    $nb_parcels         Number of parcels (default 1)
     * }
     * @return array|WP_Error  Contains ExpeditionNum and label URL on success
     */
    public static function create_shipment(array $shipment_data) {
        $creds = self::get_credentials();
        if (!$creds) {
            return new WP_Error('not_configured', __('Mondial Relay API is not configured', 'directpay-go'));
        }

        try {
            $client = self::get_soap_client();

            // Build sender address from store settings or provided data
            $mode_livraison = $shipment_data['delivery_mode'] ?? '24R'; // Default: relay delivery
            $nb_colis = $shipment_data['nb_parcels'] ?? 1;
            $weight = $shipment_data['weight'] ?? 1000; // Default 1kg in grams

            // Validate required fields
            $required = [
                'sender_address'    => $shipment_data['sender_address'] ?? '',
                'sender_city'       => $shipment_data['sender_city'] ?? '',
                'sender_postcode'   => $shipment_data['sender_postcode'] ?? '',
                'recipient_name'    => $shipment_data['recipient_name'] ?? '',
                'recipient_address' => $shipment_data['recipient_address'] ?? '',
                'recipient_city'    => $shipment_data['recipient_city'] ?? '',
                'recipient_postcode'=> $shipment_data['recipient_postcode'] ?? '',
            ];
            $missing = array_keys(array_filter($required, fn($v) => empty(trim($v))));
            if (!empty($missing)) {
                return new WP_Error(
                    'missing_fields',
                    sprintf(__('Missing required shipment fields: %s', 'directpay-go'), implode(', ', $missing)),
                    ['status' => 400]
                );
            }

            // Build parameters
            $params = [
                'Enseigne'       => $creds['enseigne'],
                'ModeCol'        => 'CCC', // Drop-off at relay by sender
                'ModeLiv'        => $mode_livraison,
                'NDossier'       => substr($shipment_data['reference'] ?? '', 0, 15),
                'NClient'        => substr($shipment_data['reference'] ?? '', 0, 9),
                'Expe_Langage'   => 'FR',
                'Expe_Ad1'       => self::sanitize_mr($shipment_data['sender_name'] ?? '', 32),
                'Expe_Ad3'       => self::sanitize_mr($shipment_data['sender_address'] ?? '', 32),
                'Expe_Ville'     => self::sanitize_mr($shipment_data['sender_city'] ?? '', 26),
                'Expe_CP'        => substr($shipment_data['sender_postcode'] ?? '', 0, 10),
                'Expe_Pays'      => strtoupper($shipment_data['sender_country'] ?? 'FR'),
                'Expe_Tel1'      => self::sanitize_phone($shipment_data['sender_phone'] ?? ''),
                'Expe_Mail'      => substr($shipment_data['sender_email'] ?? '', 0, 70),
                'Dest_Langage'   => 'FR',
                'Dest_Ad1'       => self::sanitize_mr($shipment_data['recipient_name'] ?? '', 32),
                'Dest_Ad3'       => self::sanitize_mr($shipment_data['recipient_address'] ?? '', 32),
                'Dest_Ville'     => self::sanitize_mr($shipment_data['recipient_city'] ?? '', 26),
                'Dest_CP'        => substr($shipment_data['recipient_postcode'] ?? '', 0, 10),
                'Dest_Pays'      => strtoupper($shipment_data['recipient_country'] ?? 'FR'),
                'Dest_Tel1'      => self::sanitize_phone($shipment_data['recipient_phone'] ?? ''),
                'Dest_Mail'      => substr($shipment_data['recipient_email'] ?? '', 0, 70),
                'Poids'          => (string) $weight,
                'NbColis'        => (string) $nb_colis,
                'CRT_Valeur'     => '0',
                'CRT_Devise'     => '',
                'Exp_Valeur'     => '',
                'Exp_Devise'     => '',
                'COL_Rel_Pays'   => '',
                'COL_Rel'        => '',
                'LIV_Rel_Pays'   => '',
                'LIV_Rel'        => '',
                'Texte'          => self::sanitize_mr($shipment_data['product_name'] ?? '', 30),
            ];

            // Set relay point for delivery if applicable
            if (!empty($shipment_data['relay_id'])) {
                $params['LIV_Rel_Pays'] = strtoupper($shipment_data['recipient_country'] ?? 'FR');
                $params['LIV_Rel']      = $shipment_data['relay_id'];
            }

            // Generate security hash (all parameter values concatenated + private_key)
            $hash_values = array_values($params);
            $params['Security'] = self::generate_security_hash($hash_values, $creds['private_key']);

            error_log('Mondial Relay CreateExpedition Request: ' . json_encode($params));

            $response = $client->WSI2_CreationExpedition($params);
            $result = $response->WSI2_CreationExpeditionResult;

            if (trim($result->STAT) !== '0') {
                $stat_code = trim($result->STAT);
                $stat_desc = self::get_error_description($stat_code);
                error_log('Mondial Relay CreateExpedition Error - STAT: ' . $stat_code . ' — ' . $stat_desc);
                error_log('Mondial Relay CreateExpedition Params Sent: ' . json_encode(array_diff_key($params, ['Security' => 1])));
                return new WP_Error(
                    'mr_shipment_failed',
                    sprintf(__('Mondial Relay shipment creation failed. Error code: %s — %s', 'directpay-go'), $stat_code, $stat_desc),
                    ['status' => 400, 'stat' => $stat_code, 'params_sent' => array_diff_key($params, ['Security' => 1])]
                );
            }

            return [
                'expedition_num' => trim($result->ExpeditionNum ?? ''),
                'tracking_url'   => 'https://www.mondialrelay.fr/suivi-de-colis?numeroExpedition=' . trim($result->ExpeditionNum ?? ''),
            ];

        } catch (SoapFault $e) {
            error_log('Mondial Relay SOAP Fault (CreateExpedition): ' . $e->getMessage());
            return new WP_Error('soap_fault', $e->getMessage(), ['status' => 500]);
        } catch (Exception $e) {
            error_log('Mondial Relay Error (CreateExpedition): ' . $e->getMessage());
            return new WP_Error('mr_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Get shipping label (etiquette) URL
     *
     * @param string $expedition_num The expedition number
     * @param string $format         Label format: A4, A5, 10x15 (default A4)
     * @return array|WP_Error        Contains label URL on success
     */
    public static function get_label(string $expedition_num, string $format = 'A4') {
        $creds = self::get_credentials();
        if (!$creds) {
            return new WP_Error('not_configured', __('Mondial Relay API is not configured', 'directpay-go'));
        }

        try {
            $client = self::get_soap_client();

            $params = [
                'Enseigne'      => $creds['enseigne'],
                'Expeditions'   => $expedition_num,
                'Langue'        => 'FR',
            ];

            $params['Security'] = self::generate_security_hash([
                $params['Enseigne'],
                $params['Expeditions'],
                $params['Langue'],
            ], $creds['private_key']);

            $response = $client->WSI2_GetEtiquettes($params);
            $result = $response->WSI2_GetEtiquettesResult;

            if (trim($result->STAT) !== '0') {
                error_log('Mondial Relay GetEtiquettes Error - STAT: ' . $result->STAT);
                return new WP_Error(
                    'mr_label_failed',
                    sprintf(__('Failed to get shipping label. Error code: %s', 'directpay-go'), $result->STAT),
                    ['status' => 400, 'stat' => $result->STAT]
                );
            }

            $label_url = trim($result->URL_Etiquette ?? '');
            // Ensure HTTPS
            if ($label_url && strpos($label_url, 'http://') === 0) {
                $label_url = str_replace('http://', 'https://', $label_url);
            }

            return [
                'label_url' => $label_url,
            ];

        } catch (SoapFault $e) {
            error_log('Mondial Relay SOAP Fault (GetEtiquettes): ' . $e->getMessage());
            return new WP_Error('soap_fault', $e->getMessage(), ['status' => 500]);
        } catch (Exception $e) {
            error_log('Mondial Relay Error (GetEtiquettes): ' . $e->getMessage());
            return new WP_Error('mr_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Test API connection with current credentials
     *
     * @return array|WP_Error
     */
    public static function test_connection() {
        $creds = self::get_credentials();
        if (!$creds) {
            return new WP_Error('not_configured', __('Mondial Relay API is not configured', 'directpay-go'));
        }

        // Try a simple relay point search to validate credentials
        $result = self::search_relay_points('FR', '75001', 1);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Connection successful! API credentials are valid.', 'directpay-go'),
        ];
    }

    /**
     * Parse opening hours from relay point data
     *
     * @param object $point Relay point object from API
     * @return array
     */
    private static function parse_opening_hours($point): array {
        $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $hours = [];

        foreach ($days as $index => $day) {
            $day_num = $index + 1;
            $h1 = trim($point->{"Horaires_Jour{$day_num}"} ?? '') ?: null;
            if ($h1) {
                $hours[$day] = $h1;
            }
        }

        return $hours;
    }

    /**
     * Sanitize string for Mondial Relay API (remove accents, limit length)
     *
     * @param string $str
     * @param int    $max_length
     * @return string
     */
    private static function sanitize_mr(string $str, int $max_length): string {
        // Remove accents
        $str = remove_accents($str);
        // Remove special chars that MR doesn't accept
        $str = preg_replace('/[^a-zA-Z0-9\s\-\/\.]/', '', $str);
        // Trim and limit length
        return substr(trim($str), 0, $max_length);
    }

    /**
     * Sanitize phone number for Mondial Relay API
     * Converts international format (+33 6xx) to local (06xx) and ensures max 10 digits
     *
     * @param string $phone
     * @return string
     */
    private static function sanitize_phone(string $phone): string {
        // Strip all non-digit characters
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Convert French international format (33...) to local (0...)
        if (strlen($digits) === 11 && str_starts_with($digits, '33')) {
            $digits = '0' . substr($digits, 2);
        }
        // Handle case where + was already stripped: 330761... -> 0761...
        if (strlen($digits) > 10 && str_starts_with($digits, '33')) {
            $digits = '0' . substr($digits, 2);
        }
        
        // Ensure max 10 digits
        return substr($digits, 0, 10);
    }

    /**
     * Get MR error description for a given STAT code
     *
     * @param string $stat
     * @return string
     */
    public static function get_error_description(string $stat): string {
        $errors = [
            '1'  => 'Enseigne invalide',
            '2'  => 'Numéro d\'enseigne vide ou absent',
            '3'  => 'Compte enseigne inexistant ou inactif',
            '5'  => 'Code sécurité invalide',
            '7'  => 'Nombre de résultats invalide',
            '8'  => 'Pays invalide',
            '9'  => 'Ville non reconnu ou invalide',
            '10' => 'Code postal invalide',
            '20' => 'Poids du colis invalide',
            '21' => 'Taille (longueur) du colis invalide',
            '22' => 'Taille (hauteur + longueur) du colis invalide',
            '24' => 'Numéro de départ de colis invalide',
            '25' => 'Adresse invalide',
            '26' => 'Code Postal + Ville du destinataire invalide',
            '27' => 'Code Postal + Ville de l\'expéditeur invalide',
            '28' => 'Numéro d\'expédition ou de suivi invalide',
            '29' => 'Action invalide ou expédition déjà clôturée',
            '30' => 'Mode de collecte invalide',
            '31' => 'Mode de livraison invalide',
            '33' => 'Nombre de colis invalide',
            '36' => 'Livraison non disponible pour ce couple pays/CP',
            '38' => 'Le mode de livraison n\'est pas compatible',
            '62' => 'Langue invalide',
            '80' => 'Code tracing: commande enregistrée',
            '81' => 'Code tracing: en cours de traitement',
            '82' => 'Code tracing: livré',
            '94' => 'Erreur d\'authentification',
            '95' => 'Compte non habilité (les comptes de test ne supportent pas la création d\'expéditions)',
            '97' => 'Erreur interne',
        ];

        return $errors[$stat] ?? sprintf(__('Unknown error (code: %s)', 'directpay-go'), $stat);
    }
}

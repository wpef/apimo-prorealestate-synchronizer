<?php

// Includes the core classes
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
if (!class_exists('WP_Http')) {
  require_once(ABSPATH . WPINC . '/class-http.php');
}

class ApimoProrealestateSynchronizer
{
  /**
   * Instance of this class
   *
   * @var ApimoProrealestateSynchronizer
   */
  private static $instance;

  /**
   * @var string
   */
  private $siteLanguage;

  /**
   * Constructor
   *
   * Initializes the plugin so that the synchronization begins automatically every hour,
   * when a visitor comes to the website
   */
  public function __construct()
  {
    // Retrieve site language
    $this->siteLanguage = $this->getSiteLanguage();

    // Trigger the synchronizer event every hour only if the API settings have been configured
    if (is_array(get_option('apimo_prorealestate_synchronizer_settings_options'))) {
      if (isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_provider']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_token']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency'])
      ) {
        add_action( 'hourly_sync', array( $this, 'synchronize') );

        // For debug only, you can uncomment this line to trigger the event every time the blog is loaded
        //add_action('init', array($this, 'synchronize'));
      }
    }
  }

  /**
   * Retrieve site language
   */
  private function getSiteLanguage()
  {
    return substr(get_bloginfo('language'), 0, 2);
  }

  /**
   * Creates an instance of this class
   *
   * @access public
   * @return ApimoProrealestateSynchronizer An instance of this class
   */
  public static function getInstance()
  {
    if (null === self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  /**
   * Synchronizes Apimo and Pro Real Estate plugnins estates
   *
   * @access public
   */
  public function synchronize()
  {
    // Gets the properties
    error_log("------------------SYNCHRONIZE-----------------");

    $return = $this->callApimoAPI(
      'https://api.apimo.pro/agencies/'
      . get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency']
      . '/properties',
      'GET'
    );

    //Get sold listings
    $return2 = $this->callApimoAPI(
      'https://api.apimo.pro/agencies/'
      . get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency']
      . '/properties'
      . '?status=30',
      'GET'
    );

    // Parses the JSON into an array of properties object
    $jsonBody = json_decode($return['body']);
    $jsonBody2 = json_decode($return2['body']);

    //Add SOLD to parsed
    foreach ($jsonBody2->properties as $sold_property)
      $jsonBody->properties[] = $sold_property;

    if (is_object($jsonBody) && isset($jsonBody->properties)) {
      $properties = $jsonBody->properties;

      if (is_array($properties)) {
        foreach ($properties as $property) {

          // Parse the property object
          $data = $this->parseJSONOutput($property);

          if (null !== $data) {
            // Creates or updates a listing // Image checked
            $this->manageListingPost($data);
          }
        }

        $this->deleteOldListingPost($properties);
      }
    }

  }

  /**
   * Parses a JSON body and extracts selected values
   *
   * @access private
   * @param stdClass $property
   * @return array $data
   */
  private function parseJSONOutput($property)
  {
    $data = array(
      'user' => $property->user,
      'updated_at' => $property->updated_at,
      'postTitle' => array(),
      'postContent' => array(),
      'postAuthor' => 0,
      'images' => array(),
      'customMetaPrice' => ($property->price->hide ? '' : $property->price->value ),
      'customMetaPricePrefix' => ($property->price->hide ? __('Nous consulter') : ''),
      'customMetaPricePostfix' => '',
      'customMetaSqFt' => preg_replace('#,#', '.', $property->area->value),
      'customMetaVideoURL' => '',
      'customMetaMLS' => $property->id,
      'customMetaLatLng' => ( $property->latitude && $property->longitude ? $property->latitude . ', ' . $property->longitude : '' ),
      'customMetaExpireListing' => '',
      'ct_property_type' => $property->type, //handle under WP UI > listings > Property Type
      'rooms' => 0,
      'beds' => 0,
      'customTaxBeds' => 0,
      'customTaxBaths' => 0,
      'ct_ct_status' => array(), //TO HANDLE
      'customTaxCity' => $property->city->name,
      'customTaxState' => '',
      'customTaxZip' => $property->city->zipcode,
      'customTaxCountry' => $property->country == "FR" ? "" : $property->country ,
      'customTaxCommunity' => '',
      'custom_features' => array(
        'infos' => array(
          'localisation' => "",
          'standing' => "",
          'etat' => "",
          'exposition' => "",
          'vue' => "",
          'construction' => "",
          'renovation' => "",
        ),
       'rooms' => array(),
       'services' => array(),
       'proximites' => array(),
       'reglementations' => array(),
       'customer_price' => $property->price->commission_customer,
       'isInvest' => 0,
       ),
    );

    $data['isInvest'] = $property->tags_customized[0]->value == 'Investissement' ? TRUE : FALSE ;

    $ref = array(
      'infos' => array(
        'localisation' => array(
          '',
          'Bord de mer',
          'Village',
          'Vieille ville',
          'Zone piétonne',
          'Piste de ski',
          'Centre ville',
          'Zone industrielle',
          'Centre commercial',
          'Zone d\'activité',
          'Zone aéroportuaire',
          'Technopôle',
          'Zone portuaire',
          'Périphérie',
          'Hauteurs',
          'Pieds dans l\'eau',
          'Campagne',
          'Banlieue'),
        'standing' => array('', 'Standing','Luxe','Grand luxe','Grand ensemble','Normal'),
        'etat' => array('','À rafraîchir','', 'Bon état', '', 'À rénover','Excellent état', '', 'Neuf'),
        'exposition' => array('','Est','Nord','Ouest','Sud' ),
        'vue' => array('','Vis-à-vis','Aperçu','Dégagée','Panoramique')
      ),
      'services' => array(
        "",
          "Internet",
          "Cheminée",
          "Accès handicapé",
          "Air conditionné",
          "Alarme",
          "Ascenseur",
          "Gardien",
          "Double vitrage",
          "Interphone",
          "Parabole",
          "Piscine",
          "Porte blindée",
          "Tennis",
          "Arrosage",
          "Barbecue",
          "Portail éléctrique",
          "Vide sanitaire",
          "Abri de voiture",
          "Maison de gardien",
          "Baies vitrées",
          "Aspiration centralisée",
          "Volets roulants éléctriques",
          "Stores",
          "Stores éléctriques",
          "Lave-linge",
          "Jacuzzi",
          "Sauna",
          "Baignoire balnéo",
          "Puits",
          "Source",
          "Groupe éléctrogène",
          "Lave-vaisselle",
          "Plaque de cuisson",
          "Coffre-fort",
          "Héliport",
          "Vidéophone",
          "Vidéo surveillance",
          "Cuisinière",
          "Fer à repasser",
          "Sèche-cheveux",
          "TV Satellite",
          "Lecteur DVD",
          "Lecteur CD",
          "Éclairage extérieur",
          "SPA",
          "Domotique",
          "Meublé",
          "Linge de maison",
          "Vaisselle",
          "Sèche-linge",
          "Téléphone",
          "Réfrigérateur",
          "Four",
          "Reception 24/7",
          "Cafetière",
          "Four à micro-ondes",
          "Ascenseur chabbatique",
          "Soukka",
          "Synagogue",
          "Digicode",
          "Buanderie commune",
          "Animaux autorisés",
          "Rideau métallique",
          "Baie de brassage",
          "Réseau informatique",
          "Faux plafond",
          "Robinet d'incendie armé",
          "Extincteur automatique",
          "Quai",
          "Thermostat connecté",
          "Jeu de boules",
          "Adoucisseur d'eau",
          "Triple vitrage",
          "Forage",
          "Fibre optique"
          ),
      'proximities' => array(
        "",
        "Bus",
        "Gare routière",
        "Métro",
        "Commerces",
        "École primaire",
        "Plage",
        "Centre ville",
        "Hôpital/clinique",
        "Médecin",
        "Tramway",
        "Gare",
        "Taxi",
        "Parking public",
        "Parc",
        "Supermarché",
        "Port",
        "Crèche",
        "Piscine",
        "Tennis",
        "Golf",
        "Cinéma",
        "",
        "École secondaire",
        "Salle de sport",
        "Aéroport",
        "Pistes de ski",
        "Mer",
        "Gare TGV",
        "Autoroute",
        "Université",
        "Palais des congrès",
        "Lac"
        ),
    );


    //Get agent
      if ($user = get_user_by('email', $property->user->email))
        $data['postAuthor'] = $user->ID;

      foreach ($property->comments as $comment) {
        $data['postTitle'][$comment->language] = $comment->title;
        $data['customMetaAltTitle'] = $data['postTitle'][$comment->language];
        $data['postContent'][$comment->language] = $comment->comment;
      }

    //Get beds and baths
      $data['beds'] = $property->bedrooms;
      foreach ($property->areas as $area) {

        if ($area->type == 1 || $area->type == 53 || $area->type == 70)
          $data['customTaxBeds'] += $area->number;
        else if ($area->type == 8 || $area->type == 41 || $area->type == 13 || $area->type == 42 )
          $data['customTaxBaths'] += $area->number;

      }

    //Get pictures
      foreach ($property->pictures as $picture) { 
        $data['images'][] = array(
          'id' => $picture->id,
          'url' => $picture->url,
          'rank' => $picture->rank
        );
      }
      //checked - debug 

    //Get Status
      if ($property->status == 1) //si en cours
        $data['ct_ct_status'][] = 'for-sale'; //ne rien faire

        //Gérer location -- to do

      else if ( $property->status == 30 || $property->status == 31 || $property->status == 32 || $property->status == 33 ) //si vendu 
        $data['ct_ct_status'][] = 'sold'; //ajouter au tab

    //Get features
      //Infos
        $data['custom_features']['infos']['localisation'] = $ref['infos']['localisation'][ (int)$property->location ]; //Loclisation type
        $data['custom_features']['infos']['standing'] = $ref['infos']['standing'][ (int)$property->standing ]; //standing
        $data['custom_features']['infos']['etat'] = $ref['infos']['etat'][ (int)$property->condition ]; //etat
        $data['custom_features']['infos']['exposition'] = $ref['infos']['exposition'][(int)$property->orientations[0]]; //exposition
        $data['custom_features']['infos']['vue'] = $ref['infos']['vue'][ (int)$property->view->type]; //vue
        $data['custom_features']['infos']['construction'] = $property->construction_year;
        $data['custom_features']['infos']['renovation'] = $property->renovation_year;

      //Rooms
        $i = 0;
        foreach($property->areas as $area) {
          $roomName = '';
          switch ($area->type) {
            case 4: $roomName = 'Garage' ; break;
            case 5: $roomName = 'Parking'; break;
            case 6: $roomName = 'Cave'; break;
            case 9: $roomName = 'Buanderie'; break;
            case 10: $roomName = 'Bureau'; break;
            case 14: $roomName = 'Dressing'; break;
            case 17: $roomName = 'Véranda'; break;
            case 18: $roomName = 'Terasse'; break;
            case 19: $roomName = 'Solarium'; break;
            case 21: $roomName = 'Salle de jeux'; break;
            case 22: $roomName = 'Salle à manger'; break;
            case 23: $roomName = 'Pool house'; break;
            case 26: $roomName = 'Loggia'; break;
            case 27: $roomName = 'Grenier'; break;
            case 29: $roomName = 'Mezzanine'; break;
            case 32: $roomName = 'Atelier'; break;
            case 33: $roomName = 'Studio'; break;
            case 35: $roomName = 'Bibliothèque'; break;
            case 37: $roomName = 'Cour'; break;
            case 40: $roomName = 'Sous-sol'; break;
            case 43: $roomName = 'Balcon'; break;
            case 44: $roomName = 'Salle de sport'; break;
            case 46: $roomName = 'Cinéma'; break;
            case 49: $roomName = 'Jardin'; break;
            case 52: $roomName = 'Patio'; break;
            case 54: $roomName = 'Suite'; break;
            case 59: $roomName = 'Dépendance'; break;
            case 60: $roomName = 'Local à vélo'; break;
            case 62: $roomName = 'Local à poubelles'; break;
            case 63: $roomName = 'Hammam'; break;
            case 64: $roomName = 'Piscine intérieure'; break;
            case 66: $roomName = 'Sauna'; break;
            case 74 : case 75 : $roomName = 'Parking'; break;
            case 84: $roomName = 'Escalier'; break;
            default: break;
          }
          if ($roomName !== '')
            $data['custom_features']['rooms'][$i++] = $roomName;
        }

      //Prestations
        $i = 0;
        foreach($property->services as $id) {
          $data['custom_features']['services'][$i++] = $ref['services'][ (int) $id];
        }

      //A proximites
        $i = 0;
        if (array_key_exists('proximities', $data['custom_features'])) {
        
          foreach ($property->proximities as $id) {
            $data['custom_features']['proximities'][$i++] = $ref['proximities'][ (int) $id];
          }
        }

      //Reglementations
        $i = 0;
        foreach ($property->regulations as $reg) {
          switch ($reg->type) {
            case 1:
              $data['custom_features']['reglementations'][$i++] = "Énergie - Consommation conventionnelle : " . $reg->value;
              break;

            case 2 : 
              $data['custom_features']['reglementations'][$i++] = "Énergie - Estimation des émissions : " . $reg->value;
              break;

            case 3 :
              $data['custom_features']['reglementations'][$i++] = "Loi Carrez : " . $reg->value . " m2";
              break;

            case 4 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "ERNT : Réalisé" : "ERNT : En cours";
              break;

            case 6 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "Amiante : Réalisé" : "Amiante : En cours";
              break;

            case 7 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "Gaz : Réalisé" : "Gaz : En cours";
              break;

            case 8 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "Plomb : Réalisé" : "Plomb : En cours";
              break;

            case 10 :
              $data['custom_features']['reglementations'][$i++] = "Loi Boutin : " . $reg->value;
              break;

            case 11 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "Assainissement : Réalisé" : "Assainissement : En cours";
              break;

            case 18 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "Normes accessibilité aux personnes handicapées : Réalisé" : "Normes accessibilité aux personnes handicapées : En cours";
              break;

            case 19 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == 1 ? "Agrément sanitaire : Réalisé" : "Agrément sanitaire : En cours";
              break;

            case 22 : case 24 : case 25 : case 26 :
              $data['custom_features']['reglementations'][$i++] = $reg->value > 10 ?  "Taxe foncière : " . $reg->value . " € / an" : "Taxe foncière : " . $reg->value;
              break;

            case 23 : case 28 :
              $data['custom_features']['reglementations'][$i++] = $reg->value > 10 ? "Taxe d'habitation : "  . $reg->value . " € / an" : "Taxe d'habitation : " . $reg->value;
              break;

            case 46 :
              $data['custom_features']['reglementations'][$i++] = $reg->value == "1" ? "Autorisation de vente CCH L443-12 : Réalisé" : "Autorisation de vente CCH L443-12 : En cours";
              break;

            default: break;
          }        
        }

        $data['custom_features']['reglementations'][$i++] = "Honoraire client : " . $property->price->commission_customer . " €";

    return $data;
  }

  /**
   * Creates or updates a listing post
   *
   * @param array $data
   */
  private function manageListingPost($data)
  {
    //Converts the data for later use
      $postTitle = $data['postTitle'][$this->siteLanguage];
      if ($postTitle == '') {
        foreach ($data['postTitle'] as $lang => $title) {
          $postTitle = $title;
        }
      }

      $postContent = $data['postContent'][$this->siteLanguage];
      if ($postContent == '') {
        foreach ($data['postContent'] as $lang => $title) {
          $postContent = $title;
        }
      }

    //set post infos from datas
      $postAuthor = $data['postAuthor'];
      $postUpdatedAt = $data['updated_at'];
      $images = $data['images'];
      $customMetaAltTitle = $data['customMetaAltTitle'];
      $ctPrice = str_replace(array('.', ','), '', $data['customMetaPrice']);
      $customMetaPricePrefix = $data['customMetaPricePrefix'];
      $customMetaPricePostfix = $data['customMetaPricePostfix'];
      $customMetaSqFt = $data['customMetaSqFt'];
      $customMetaVideoURL = $data['customMetaVideoURL'];
      $customMetaMLS = $data['customMetaMLS'];
      $customMetaLatLng = $data['customMetaLatLng'];
      $customMetaExpireListing = $data['customMetaExpireListing'];
      $ctPropertyType = $data['ct_property_type'];
      $rooms = $data['rooms'];
      $beds = $data['beds'];
      $customTaxBeds = $data['customTaxBeds'];
      $customTaxBaths = $data['customTaxBaths'];
      $ctCtStatus = $data['ct_ct_status'];
      $customTaxCity = $data['customTaxCity'];
      $customTaxState = $data['customTaxState'];
      $customTaxZip = $data['customTaxZip'];
      $customTaxCountry = $data['customTaxCountry'];
      $customTaxCommunity = $data['customTaxCommunity'];
      $features = $data['custom_features'];

    //Set listing post infos
      $postInformation = array(
        'post_title' => wp_strip_all_tags(trim($postTitle)),
        'post_content' => $postContent,
        'post_type' => 'listings',
        'post_status' => 'publish',
        'post_author' => $postAuthor,
      );

    //Check if listing exists
    if ($postTitle != '') {
      $post = get_page_by_title($postTitle, OBJECT, 'listings');

      //if not : Create post
        if (NULL === $post) { $postId = wp_insert_post($postInformation); }
      
      //if already exists
        else {

          $postInformation['ID'] = $post->ID;
          $postId = $post->ID;

          //Unset all terms
          wp_delete_object_term_relationships( $post->ID, 'custom_features' );

          //Check if price changed
          if ( esc_attr(strip_tags( $data['customMetaPrice'])) !== get_post_meta( $post->ID, '_ct_price', true ) ) //compare prices from post and datas
          { 
              $postInformation['post_date'] = current_time( 'mysql' ); //reset date
          } 

          // Update post
          wp_update_post($postInformation);
        }

      // Delete attachments that has been removed
        $attachments = get_attached_media('image', $postId); //is array(0) - debug

      // Updates the image and the featured image with the first given image
        $imagesIds = array();

        foreach ($images as $image) {
          // Tries to retrieve an existing media
          $media = $this->isMediaPosted($image['id']);

          //If the media does not exist, upload it
          if (!$media) {
            media_sideload_image($image['url'], $postId);

            // Retrieve the last inserted media
            $args = array(
              'post_type' => 'attachment',
              'numberposts' => 1,
              'orderby' => 'date',
              'order' => 'DESC',
            );
            $medias = get_posts($args);

            // Just one media, but still an array returned by get_posts
            foreach ($medias as $attachment) {
              // Make sure the media's name is equal to the file name
              wp_update_post(array(
                'ID' => $attachment->ID,
                'post_name' => $postTitle,
                'post_title' => $postTitle,
                'post_content' => $image['id'],
              ));
              $media = $attachment;
            }
          }

          if (!empty($media) && !is_wp_error($media)) {
            $imagesIds[$image['rank']] = $media->ID;
           
              if ($media->post_parent == 0) {
                $arg = array('ID' => $media->ID);
                wp_insert_attachment( $arg, false, $postId);
              }

          }

          // Set the first image as the thumbnail
          if ($image['rank'] == 1) {
            set_post_thumbnail($postId, $media->ID);
          }
        }

        $positions = implode(',', $imagesIds);
        update_post_meta($postId, '_ct_images_position', $positions);

      //Updates custom meta
        update_post_meta($postId, '_ct_listing_alt_title', esc_attr(strip_tags($customMetaAltTitle)));
        update_post_meta($postId, '_ct_price', esc_attr(strip_tags($ctPrice)));
        update_post_meta($postId, '_ct_price_prefix', esc_attr(strip_tags($customMetaPricePrefix)));
        update_post_meta($postId, '_ct_price_postfix', esc_attr(strip_tags($customMetaPricePostfix)));
        update_post_meta($postId, '_ct_sqft', esc_attr(strip_tags($customMetaSqFt)));
        update_post_meta($postId, '_ct_video', esc_attr(strip_tags($customMetaVideoURL)));
        update_post_meta($postId, '_ct_mls', esc_attr(strip_tags($customMetaMLS)));
        update_post_meta($postId, '_ct_latlng', esc_attr(strip_tags($customMetaLatLng)));
        update_post_meta($postId, '_ct_listing_expire', esc_attr(strip_tags($customMetaExpireListing)));

      //Updates custom taxonomies
        wp_set_post_terms($postId, $ctPropertyType, 'property_type', FALSE);
        wp_set_post_terms($postId, $beds, 'beds', FALSE);
        wp_set_post_terms($postId, $customTaxBaths, 'baths', FALSE);
        wp_set_post_terms($postId, $ctCtStatus, 'ct_status', FALSE);
        wp_set_post_terms($postId, $customTaxState, 'state', FALSE);
        wp_set_post_terms($postId, $customTaxCity, 'city', FALSE);
        wp_set_post_terms($postId, $customTaxZip, 'zipcode', FALSE);
        wp_set_post_terms($postId, $customTaxCountry, 'country', FALSE);
        wp_set_post_terms($postId, $customTaxCommunity, 'community', FALSE);

      //Update features infos
        $parent_id = term_exists( 'infos', 'custom_features'); //Get Infos taxo ID (must be set in WP UI)
        if ($parent_id !== 0 && $parent_id !== null)  {
          
          foreach ($features['infos'] as $key => $val) {
            
            // Get info type ID
            $info_id = term_exists( $key, 'custom_features', $parent_id['term_id']); //must be set in WP UI
            $info_id = (int) $info_id['term_id'];
            // Get term if exists
            $val = (string) $val;
            $val_id = term_exists($val, 'custom_features', $info_id);
            // If term exists do nothing
            if ($val_id !== 0 && $val_id != null)
              ;
            // Else, add term to database
            else if ($val_id = wp_insert_term($val, 'custom_features', array('parent' => $info_id)) == null)
                error_log("ERREUR : en essayant d'insérer nouvelle info : " . $val_id->get_error_message() . " : ". "$val in $info_id"."<br>");
            //set post terms
            wp_set_object_terms($postId, array((int) $val_id['term_id']) , 'custom_features', true);

          }
        } else {
          ;// ENVENTUALLY : insert_new_term
        }

      //Update features rooms
        $parent_id = term_exists( 'rooms', 'custom_features'); //Get rooms taxo ID (must be set in WP UI)
        $parent_id = (int) $parent_id['term_id'];

        if ($parent_id !== 0 && $parent_id !== null) {
          
          foreach ($features['rooms'] as $room) {
            //Get term if exists
            $room_id = term_exists($room, 'custom_features', $parent_id);
            // If term exists do nothing
            if ($room_id !== 0 && $room_id != null)
              ;
            // Else, add term to database
            else if ($room_id = wp_insert_term($room, 'custom_features', array('parent' => $parent_id)) == null)
                error_log("ERREUR : Insértion d'une nouvelle pièce : " . $room_id->get_error_message() . " : ". "$room in $parent_id"."<br>");
            
            //set post terms
            wp_set_object_terms($postId, array((int) $room_id['term_id']) , 'custom_features', true);

          }

        }

      //Update features regulations
        $parent_id = term_exists( 'reglementations', 'custom_features'); //Get regl taxo ID (must be set in WP UI)
        $parent_id = (int) $parent_id['term_id'];

        if ($parent_id !== 0 && $parent_id !== null) {
          
          foreach ($features['reglementations'] as $reg) {
            //Get term if exists
            $reg_id = term_exists($reg, 'custom_features', $parent_id);
            // If term exists do nothing
            if ($reg_id !== 0 && $reg_id != null)
              ;
            // Else, add term to database
            else if ($reg_id = wp_insert_term($reg, 'custom_features', array('parent' => $parent_id)) == null)
                error_log("ERREUR : Insértion d'une nouvelle règle : " . $reg_id->get_error_message() . " : ". "$reg in $reg_id"."<br>");
            //set post terms
            wp_set_object_terms($postId, array((int) $reg_id['term_id']) , 'custom_features', true);

          }

        }

      //Update features services
        $parent_id = term_exists( 'services', 'custom_features'); //Get serv taxo ID (must be set in WP UI)
        $parent_id = (int) $parent_id['term_id'];

        if ($parent_id !== 0 && $parent_id !== null) {
          
          foreach ($features['services'] as $serv) {
            //Get term if exists
            $serv_id = term_exists($serv, 'custom_features', $parent_id);
            // If term exists do nothing
            if ($serv_id !== 0 && $serv_id != null)
              ;
            // Else, add term to database
            else if ($serv_id = wp_insert_term($serv, 'custom_features', array('parent' => $parent_id)) == null)
                error_log("ERREUR : Insértion d'une nouvelle prestation : " . $serv_id->get_error_message() . " : ". "$serv in $serv_id"."<br>");
            //set post terms
            wp_set_object_terms($postId, array((int)$serv_id['term_id']) , 'custom_features', true);

          }

        }

      //Update features proximities
        $parent_id = term_exists( 'proximities', 'custom_features'); //Get prox taxo ID (must be set in WP UI)
        $parent_id = (int) $parent_id['term_id'];

        if ($parent_id !== 0 && $parent_id !== null) {
          
          if ( array_key_exists( 'proximities' , $features ) ) {
          
            foreach ($features['proximities'] as $prox) {
              
              //Get term if exists
              $prox_id = term_exists($prox, 'custom_features', $parent_id);
              // If term exists do nothing
              if ($prox_id !== 0 && $prox_id != null)
                ;
              // Else, add term to database
              else if ($prox_id = wp_insert_term($prox, 'custom_features', array('parent' => $parent_id)) == null)
                  error_log("ERREUR : Insértion d'une nouvelle proximites : " . $prox_id->get_error_message() . " : ". "$prox in $prox_id"."<br>");
              
              //set post terms
              wp_set_object_terms($postId, array((int)$prox_id['term_id']) , 'custom_features', true);

            }

          }

        }       
    
    }
  }

  /**
   * Delete old listings
   *
   * @param $properties
   */
  private function deleteOldListingPost($properties)
  {
    $parsedProperties = array();

    // Parse once for all the properties
    foreach ($properties as $property) {
      $parsedProperties[] = $this->parseJSONOutput($property);
    }

    // Retrieve the current posts
    $posts = get_posts(array(
      'post_type' => 'listings',
      'numberposts' => -1,
    ));

    foreach ($posts as $post) {
      $postMustBeRemoved = true;

      // Verifies if the post exists
      foreach ($parsedProperties as $property) {
        $postTitle = $property['postTitle'][$this->siteLanguage];
        if ($postTitle == '') {
          foreach ($property['postTitle'] as $lang => $title) {
            $postTitle = $title;
          }
        }

        if ($postTitle == $post->post_title) {
          $postMustBeRemoved = false;
          break;
        }
      }

      // If not, we can execute the action
      if ($postMustBeRemoved) {
        // Delete the post
        wp_delete_post($post->ID);
      }
    }
  }

  /**
   * Verifies if a media is already posted or not for a given image URL.
   *
   * @access private
   * @param int $imageId
   * @return object
   */
  private function isMediaPosted($imageId)
  {
    $args = array(
      'post_type' => 'attachment',
      'posts_per_page' => -1,
      'post_status' => 'any',
      'content' => $imageId,
    );

    $medias = ApimoProrealestateSynchronizer_PostsByContent::get($args);

    if (isset($medias) && is_array($medias)) {
      foreach ($medias as $media) {
        return $media;
      }
    }

    return null;
  }

  /**
   * Return the filename for a given URL.
   *
   * @access private
   * @param string $imageUrl
   * @return string $filename
   */
  private function getFileNameFromURL($imageUrl)
  {
    $imageUrlData = pathinfo($imageUrl);
    return $imageUrlData['filename'];
  }

  /**
   * Calls the Apimo API
   *
   * @access private
   * @param string $url The API URL to call
   * @param string $method The HTTP method to use
   * @param array $body The JSON formatted body to send to the API
   * @return array $response
   */
  private function callApimoAPI($url, $method, $body = null)
  {
    $headers = array(
      'Authorization' => 'Basic ' . base64_encode(
          get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_provider'] . ':' .
          get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_token']
        ),
      'content-type' => 'application/json',
    );

    if (null === $body || !is_array($body)) {
      $body = array();
    }

    if (!isset($body['limit'])) {
      $body['limit'] = 100;
    }
    if (!isset($body['offset'])) {
      $body['offset'] = 0;
    }

    $request = new WP_Http;
    $response = $request->request($url, array(
      'method' => $method,
      'headers' => $headers,
      'body' => $body,
    ));

    if (is_array($response) && !is_wp_error($response)) {
      $headers = $response['headers']; // array of http header lines
      $body = $response['body']; // use the content
    } else {
      $body = $response->get_error_message();
    }
    
    return array(
      'headers' => $headers,
      'body' => $body,
    );
  }

  /**
   * Activation hook
   */
  public static function install()
  {
    if (!wp_next_scheduled('hourly_sync')) {
      wp_schedule_event(time(), 'hourly', 'hourly_sync');
    }
  }

  /**
   * Deactivation hook
   */
  public static function uninstall()
  {
    wp_clear_scheduled_hook('hourly_sync');
  }
}

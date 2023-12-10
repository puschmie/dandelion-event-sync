<?php
/**
* Plugin Name:       Dandelion Event Synchronisation
* Description:       Synchronizes events from your Dandelion organization once a day or manually.
* Requires at least: 6.1
* Requires PHP:      7.0
* Version:           0.1.0
* Author:            Jonas Gröner
* License:           GPL-2.0-or-later
* License URI:       https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain:       dandelion-event-sync
*/


// Define constants for cron interval
define('EVENT_SYNC_CRON_INTERVAL', 'daily');

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'des_activate_event_sync');
register_deactivation_hook(__FILE__, 'deactivate_event_sync');

// Activate the scheduled event on plugin activation
function des_activate_event_sync() {
    $schedule_time = strtotime('today 24:00:00');
    wp_schedule_event($schedule_time, EVENT_SYNC_CRON_INTERVAL, 'des_event_sync_cron_hook');
}

// Deactivate the scheduled event on plugin deactivation
function des_deactivate_event_sync() {
    wp_clear_scheduled_hook('des_event_sync_cron_hook');
}

// Hook into the scheduled event
add_action('des_event_sync_cron_hook', 'des_sync_events_from_api');

// Hook into admin menu to add a dashboard page
add_action('admin_menu', 'des_event_sync_menu');

// Function to add a menu item in the WordPress admin
function des_event_sync_menu() {
    add_menu_page(
        'Event Sync',
        'Event Sync',
        'manage_options',
        'des_dashboard',
        'des_dashboard_page',
        'dashicons-download',
        30
    );
}

//register plugin settings
function des_settings(){
    register_setting('des_settings','des_dandelion_account');
}
add_action('admin_init','des_settings');

// Function to display the dashboard page
function des_dashboard_page() {
    ?>
    <div class="wrap">
        <h2>Event Sync Dashboard</h2>
        <h3>Sync Events Manually</h3>
        <form method="post" action=<?php menu_page_url('event_sync_dashboard')?>>
            <?php wp_nonce_field('manual_sync','manual_sync_nonce');?>
            <?php submit_button('Sync Events Now', 'primary', 'manual_sync_submit'); ?>
        </form>

      <?php  if (isset($_GET['success'])) {
        if($_GET['success'] == 1){
            echo '<div class="notice notice-success is-dismissible"><p>Request successful! '.$_GET['events_imported'].' events imported. '.$_GET['events_updated'].' events updated.</p></div>';
        }else{
            echo '<div class="notice notice-error is-dismissible"><p>Something went wrong.</p></div>';
        }
       
      } ?>

        <h3>Dandelion Account</h3>
        <form method="post" action="options.php">
            <?php settings_fields('des_settings');?>
            <?php do_settings_sections('des_settings');?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Dandelion Account Slug: </th>
                    <td>
                        <input type="text" name="des_dandelion_account" value="<?php echo esc_attr(get_option('des_dandelion_account')); ?>" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
    </div>
    <?php
}

// Hook into form submission to manually trigger sync
add_action('admin_post_manual_sync', 'des_handle_manual_sync');

// Function to handle manual sync form submission
function des_handle_manual_sync() {
    // Check nonce for security

    session_start();
    if (isset($_SESSION['manual_sync_nonce']) && wp_verify_nonce($_SESSION['manual_sync_nonce'], 'manual_sync')) {
      
        // Trigger manual event sync
          $events=des_sync_events_from_api();
         wp_redirect(admin_url('admin.php?page=des_dashboard&success='.$events["success"].'&events_imported='.$events["num_posts_added"].'&events_updated='.$events["num_posts_updated"]));
       
    }else{
        wp_redirect(admin_url('admin.php?page=des_dashboard&success=0'));
    }
    session_destroy();
    
    exit();
}

//function to import an image from a given URL

function des_add_thumbnail($url, $post_id){
    // Gives us access to the download_url() and wp_handle_sideload() functions
    require_once( ABSPATH . 'wp-admin/includes/file.php' );

    // URL to the WordPress logo
    $timeout_seconds = 5;

    // Download file to temp dir
    $temp_file = download_url( $url, $timeout_seconds );

    if ( !is_wp_error( $temp_file ) ) {

        // Array based on $_FILE as seen in PHP file uploads
        $file = array(
            'name'     => basename($url), // ex: wp-header-logo.png
            'type'     => 'image/png',
            'tmp_name' => $temp_file,
            'error'    => 0,
            'size'     => filesize($temp_file),
        );

        $overrides = array(
            // Tells WordPress to not look for the POST form
            // fields that would normally be present as
            // we downloaded the file from a remote server, so there
            // will be no form fields
            // Default is true
            'test_form' => false,

            // Setting this to false lets WordPress allow empty files, not recommended
            // Default is true
            'test_size' => true,
        );

        // Move the temporary file into the uploads directory
        $results = wp_handle_sideload( $file, $overrides );
    
        if ( !empty( $results['error'] ) ) {
            // Insert any error handling here
            print_r($results['error']);
        } else {           
            
            $attachment = array(
                'guid'           => $wp_upload_dir['url'] . '/' . basename( $results['file']), 
                'post_mime_type' => $results['type'],
                'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $results['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );
    
            $attach_id = wp_insert_attachment( $attachment, basename($results['file']), $post_id);
            set_post_thumbnail( $post_id, $attach_id );
            return $attach_id;
        }

    }
}

function des_create_new_event($event){
   
    //assemble post content, including booking form
    $new_post_content = des_assemble_post_content($event);

    $postarr = array(
        'post_content'=>$new_post_content,
        'post_title'=>$event['name'],
        'post_status'=>'publish',
        'post_type'=>'event',
    );
    $post = wp_insert_post($postarr);

    //add thumbnail
    des_add_thumbnail($event['image'], $post);
    


    //add date, location, registration link, facilitator to ACF   

    if(function_exists('update_field')){
        update_field('date_time',$event["start_time"],$post);
        update_field('location', $event["location"],$post);
        update_field('partner', des_make_facilitators($event["facilitators"]), $post);
        update_field('location', $event["location"],$post);
        update_field('registration_link', des_make_booking_link($event["id"]), $post);
    } 

    //process tags into project and topics
    $project_topics = des_get_project_topics($event["tags"]);
    
    //set the project if it exists
    if($project_topics["project"]){
        update_field('project', $project_topics["project"], $post);
    }

    //set taxonomies
    wp_set_post_terms($post, $project_topics["topics"], 'topic');

}

//extract facilitators from event array and return them in an appropriate format for the ACF field
function des_make_facilitators($facilitators){
    $facilitators_string = '';
    foreach($facilitators as $fac){
        $facilitators_string = $facilitators_string.$fac["name"].', ';
    }
    return $facilitators_string;
}

//function to process dandelion tags in to project ID and tag array for topics taxonomy
function des_get_project_topics($tags){
    $project_topics = array(
        "project" => '',
        "topics" => array()
    );

    foreach ($tags as $tag) {
        // Custom query parameters
        $args = array(
            'post_type'      => 'project',  // Custom post type
            'posts_per_page' => 1,          // Set to the number of posts you want to retrieve
            's'              => $tag,       // Search query based on each tag
        );
    
        // Perform the query
        $query = new WP_Query($args);
        // Check if there are any posts and if the project has not been set yet
        if ($query->have_posts() && !$project_topics["project"]) {
            // Get the ID of the first matching project
            $query->the_post();
            $project_topics["project"] = get_the_ID();
            wp_reset_postdata(); // Restore global post data
        }else{
            $project_topics["topics"][]=$tag;
        }
    }

    return $project_topics;
}

//assemble post content from event description and registration form
function des_assemble_post_content($event){
    $html_block = 
    '<!-- wp:html -->
        <iframe style="overflow: scroll; border: 0; width:100%; height: 100vh" class="dandelion-auto-height" src="'.des_make_booking_link($event["id"]).'"></iframe>

    <!-- /wp:html -->';

    return $event["description"].$html_block;
}

//function to make the booking link
function des_make_booking_link($event_id){
    return "https://dandelion.events/events/".$event_id.'/?ticket_form_only=1';
}

// Function to sync events from API
function des_sync_events_from_api() {
    // Implement logic to fetch events from the API and sync with WordPress posts
    // Use wp_remote_get or another HTTP library for API requests
    $url = "https://dandelion.events/o/".get_option('des_dandelion_account')."/events.json";
    $api_response = wp_remote_get($url);

    $feedback = array(
        "success"           =>  bool,
        "num_posts_added"   =>  0, 
        "posts_added_titles"=>  array(), 
        "num_posts_update"  =>  0, 
        "error"             =>  WP_Error
    );
    
    if (!is_wp_error($api_response) && $api_response['response']['code'] === 200) {
        
        $feedback["success"]=true;
        $events= json_decode($api_response['body'], true);    
        if (!empty($events) && is_array($events)) {
            foreach ($events as $event) {
                $title=$event['name'];
                // Implement logic to check if the event already exists in WordPress
                // If not, create a new post using wp_insert_post and set post meta as needed
                // Update post content, title, meta fields, etc.
                $post = get_page_by_title($title, OBJECT, 'event');
               
                if ( empty($post)){
                    des_create_new_event($event);
                    $feedback["num_posts_added"]++;
                    $feedback["posts_added_titles"][] = $title;
                }else{
                    $updated = des_update_event($post,$event);
                    if($updated){
                        $feedback["num_posts_updated"]++;
                    }
                } 
            }
        }
    }if(is_wp_error($api_response)){
        $feedback["success"]=false;
        $feedback["error"]=$api_response;
    }
    return $feedback;
}

//check if an existing post matches the data in an event retrieved from dandelion
function des_update_event($post,$event){
    $updated = false;
    
    //update post content if it doesn't match
    if($post->post_content != des_assemble_post_content($event)){
        wp_update_post(array(
            'ID' => $post->ID,
            'post_content' => $event["description"]
        ));
        $updated = true;
   }
   //get project and topics
   $project_topics = des_get_project_topics($event["tags"]);

   //assemble new acf fields array
   $new_fields= array(
        "date_time"=> $event["start_time"],
        "location" => $event["location"],
        "partner" => des_make_facilitators($event["facilitators"]),
        "project" => $project_topics["project"],
        "registration_link" => des_make_booking_link($event["id"])
   );

   //get exisiting acf fields
   $existing_acf_fields = get_fields($post->ID, false);
 
   //update existing acf fields if they don't match the values in the new acf fields array
   if($existing_acf_fields){
        foreach($new_fields as $field_name => $field_value){
            if(!$existing_acf_fields[$field_name] || $existing_acf_fields[$field_name] != $field_value){
                update_field($field_name, $new_fields[$field_name], $post->ID);
                $updated = true;
            }
        }
    }

    //update topics
    wp_set_post_terms($post->ID, $project_topics["topics"], 'topic');
     
    //check if filename of thumbnail matches slug of image url
    $thumbnail_url = get_the_post_thumbnail_url($post->ID);
    
    // Check if a thumbnail exists
    if ($thumbnail_url) {
        // Extract the filename from the post thumbnail URL
        $thumbnail_filename = basename($thumbnail_url);
        
        // Extract the slug from the API image URL
        $dandelion_image_slug = pathinfo($event["image"], PATHINFO_FILENAME).'.'.pathinfo($event["image"],PATHINFO_EXTENSION);
       
        // Compare the filename of the post thumbnail with the slug from the API image URL
        if ($thumbnail_filename !== $dandelion_image_slug) {
           des_add_thumbnail($event["image"], $post->ID);
        } else {
            echo 'The filename does not match the slug.';
        }
    }else{
        des_add_thumbnail($event["image"], $post->ID);
   
    return $updated;
    }
}

// Hook into WordPress init to handle manual sync button click
add_action('init', 'des_handle_manual_sync_button_click');

// Function to handle manual sync button click
function des_handle_manual_sync_button_click() {
    if (isset($_POST['manual_sync_submit'])) {
        //store nonce in session variable for use in handler function
        session_start();
        $_SESSION['manual_sync_nonce']=$_POST['manual_sync_nonce'];
        // Redirect to the admin-post URL to handle the form submission
        wp_redirect(admin_url('admin-post.php?action=manual_sync'));
        exit();
    }
}



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

//add resize code to head
function des_add_js_to_head(){
    if(is_singular('event')){
        echo '
            <script src="//code.jquery.com/jquery-latest.js"></script>
            <script src="//cdnjs.cloudflare.com/ajax/libs/iframe-resizer/4.2.1/iframeResizer.min.js"></script>
            <script>
            $(function () {
            $(".dandelion-auto-height").iFrameResize({log: true, checkOrigin: false, heightCalculationMethod : "taggedElement"})
            })
            </script>
        ';
    }
}
add_action('wp_head', 'des_add_js_to_head');

// Function to display the dashboard page
function des_dashboard_page() {
    ?>
    <div class="wrap">
        <h2>Event Sync Dashboard</h2>
        <h3>Import Events</h3>
        <form method="post" action=<?php echo esc_url(admin_url('admin-post.php'));?>>
            <input type="hidden" name="action" value="manual_import">
            <?php wp_nonce_field('manual_import','manual_import_nonce');?>
            <?php submit_button('Import Events Now', 'primary', 'submit_manual_import'); ?>
        </form>
        <h3>Update Events</h3>
        <form method="post" action=<?php echo esc_url(admin_url('admin-post.php'));?>>
            <input type="hidden" name="action" value="manual_update">
            <?php wp_nonce_field('manual_update','manual_update_nonce');
            $today_date = date('Ymd');

            // Dropdown for selecting an event
            $args = array(
                'post_type' => 'event',
                'posts_per_page' => -1, // Retrieve all events
                'meta_key' => 'date_time', // Replace 'event_date' with your actual ACF date field name
                'orderby' => 'meta_value_num',
                'order' => 'ASC',
                'meta_query' => array(
                    array(
                        'key' => 'date_time', // Replace 'event_date' with your actual ACF date field name
                        'value' => $today_date,
                        'compare' => '>=', // Only shows events with the date today or in the future
                        'type' => 'DATE'
                    ),
                ),
            );
            $events = new WP_Query($args);
            if ($events->have_posts()) : ?>
                <label>Choose the event you would like to update.</label>
                <select name="event_id" id="event_id">
                    <?php while ($events->have_posts()) : $events->the_post(); ?>
                        <option value="<?php the_ID(); ?>"><?php the_title(); ?></option>
                    <?php endwhile; ?>
                </select>
            <?php endif; wp_reset_postdata(); ?>
            <?php submit_button('Update selected Event Now', 'primary', 'manual_update_submit'); ?>
        </form>

      <?php  
        if (isset($_GET['success'])) {
            if(isset($_GET['events_imported'])){
                if($_GET['success'] == 1 && $_GET['events_imported']){
                    echo '<div class="notice notice-success is-dismissible"><p>Request successful! '.$_GET['events_imported'].' events imported.</p></div>';
                }
                if($_GET['success'] == 1 && $_GET['events_imported']==0){
                    echo '<div class="notice notice-success is-dismissible"><p>No new events to import.</p></div>';
                }
            }
            if(isset($_GET['event_updated'])){
                if($_GET['success']==1 && $_GET['event_updated']){
                    echo '<div class="notice notice-success is-dismissible"><p>Request successful! '.$_GET['event_updated'].' event was updated: "'.$_GET['event_updated_title'].'"</p></div>';
                }
            }
            if(!$_GET['success']){
                echo '<div class="notice notice-error is-dismissible"><p>Something went wrong.</p></div>';
    
            }
        }
        
       
       ?>

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

// Hook into form submission to manually trigger import
add_action('admin_post_manual_import', 'des_handle_manual_import');

// Function to handle manual sync form submission
function des_handle_manual_import() {
    // Check nonce for security
   
    if ( wp_verify_nonce($_POST['manual_import_nonce'], 'manual_import')) {
        
        // Trigger manual event sync
        $events=des_import_events();
        wp_redirect(admin_url('admin.php?page=des_dashboard&success='.$events["success"].'&events_imported='.$events["num_posts_added"]));
       
    }else{
        wp_redirect(admin_url('admin.php?page=des_dashboard&success=0'));
    }
    
    exit();
}

// Hook into form submission to manually trigger update
add_action('admin_post_manual_update', 'des_handle_manual_update');

// Function to handle manual update form submission
function des_handle_manual_update() {
    // Check nonce for security
    if (wp_verify_nonce($_POST['manual_update_nonce'], 'manual_update') && isset($_POST['event_id'])) {
        
        // Trigger manual event update
        $event=des_update_event($_POST['event_id']);
         wp_redirect(admin_url('admin.php?page=des_dashboard&success='.$event["success"].'&event_updated='.$event["post_updated"].'&event_updated_title='.$event["post_updated_title"]));
       
    }else{
        wp_redirect(admin_url('admin.php?page=des_dashboard&success=0'));
    }
    
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
        $wp_upload_dir = wp_upload_dir(); 
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
        update_field('end_time', $event["end_time"],$post);
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

    $html = des_make_booking_form($event["id"]);
    return $event["description"].$html;
}

//function to make the booking link
function des_make_booking_link($event_id){
    return "https://dandelion.events/events/".$event_id.'/?ticket_form_only=1';
}

function des_make_booking_form($event_id){
    $registration_form_html = 
    '
    <!-- wp:html -->
        <p>
        <iframe style="overflow: scroll; border: 0; width:100%; height: 100vh" class="dandelion-auto-height" src="'.des_make_booking_link($event_id).'"></iframe>
        </p>
    <!-- /wp:html -->
    ';
    return $registration_form_html;
}

//Get events from API
function des_get_events(){
     // Implement logic to fetch events from the API and sync with WordPress posts
    // Use wp_remote_get or another HTTP library for API requests
    $url = "https://dandelion.events/o/".get_option('des_dandelion_account')."/events.json";
    $api_response = wp_remote_get($url);
    if(!is_wp_error($api_response) && $api_response['response']['code'] === 200) {
        $events= json_decode($api_response['body'], true); 
       
        return $events; 
    }if(is_wp_error($api_response)){
        error_log('API Request failed: '.$api_response);
        return $api_response;
    }
}

// Function to import events
function des_import_events() {

    $feedback = array(
        "success"           =>  false,
        "num_posts_added"   =>  0, 
        "posts_added_titles"=>  array(), 
        "error"             =>  ''
    );
    $events = des_get_events();   
        
    if (!empty($events) && is_array($events)) {
        foreach ($events as $event) {
            $title=$event['name'];
            // Implement logic to check if the event already exists in WordPress
            // If not, create a new post using wp_insert_post and set post meta as needed
            $post = get_page_by_title($title, OBJECT, 'event');
            
            if ( empty($post)){
                des_create_new_event($event);
                $feedback["num_posts_added"]++;
                $feedback["posts_added_titles"][] = $title;
            }
        }
        $feedback["success"]=true;
    }if(is_wp_error($events)){
        $feedback["error"]=$events;
        error_log('Error retrieving data: ' . $response->get_error_message());
    }
   
    return $feedback;
}

//update events
function des_update_event($post_id){

    $feedback = array(
        "success"           =>  false,
        "post_updated"   =>  false, 
        "post_updated_title"=>  '', 
        "error"             =>  ''
    );
    $post = get_post($post_id);
    $events = des_get_events();

    //find the event that matches the title of the selected post
    foreach ($events as $event) {
        $title=$event['name'];
        // Implement logic to check if the event already exists in WordPress
        // If it does, update it        
        if ($post->post_title==$title){
            //update post content
            $content = des_assemble_post_content($event);
            wp_update_post(array(
                'ID' => $post->ID,
                'post_content' => $content
            ));
                    
            
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
                }
            $feedback['success']=true;    
            $feedback['post_updated']=true;
            $feedback['post_updated_title']=$title;
            break;
        }
    }
    return $feedback;
}



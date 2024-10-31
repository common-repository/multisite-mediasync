<?php
/* Plugin name: Multisite Mediasync
   Plugin URI: http://www.handschlag.io
   Author: seangruenboeck
   Author URI: http://www.handschlag.io
   Version: 1.0
   Description: Multisite MediaSync syncs the Media Posts in WP Posts tables for all blogs within a multisite installation.
And it makes sure that all uploads are only saved and read from the main uploads folder.
   Max WP Version: 4.6
   Text Domain: multisite-mediasync

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! function_exists('write_log')) {
   function write_log ( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( var_dump( $log, true ) );
      } else {
         error_log( $log );
      }
   }
}

function msms_getIDfromGUID( $guid ){
  global $wpdb;
  return $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s", $guid ) );
}

function msms_getBlogs() {
  global $wpdb;
  return $wpdb->get_results('SELECT blog_id FROM wp_blogs');
}

class MultisiteMediaSync {
  
	function MultisiteMediaSync() {
		add_action( 'admin_menu', array(&$this, 'addAdminMenu') );
	}

	function addAdminMenu() {
		add_management_page( __( 'Multisite MediaSync', 'ajax-thumbnail-rebuild' ), __( 'Multisite MediaSync', 'multisite-mediasync'
 ), 'manage_options', 'multisite-mediasync', array(&$this, 'ManagementPage') );
	}

  function ManagementPage() {
    
    echo "<style>.update-nag { display: none; }</style>";
  	
    function sanitize($str) {
      return str_replace("'","\'",$str);
    }
    
    function add_quotes($str) {
      return sprintf("'%s'", sanitize($str));
    }
    
    function objectToQuery($obj) {
      $arr = get_object_vars($obj);
      return implode(",", array_map('add_quotes', $arr));
    }
    
    function testRow($row) {
      echo "<pre>";
      echo (var_dump($row));
      echo "</pre>";
    }
    
    function postSync($from_blog_id, $to_blog_id) {
      global $wpdb;
      
      // Build Query to check which posts should be synced
      
      $from_posts_table = $wpdb->get_blog_prefix($from_blog_id) . "posts";
      $to_posts_table = $wpdb->get_blog_prefix($to_blog_id) . "posts";
      
      $query = "SELECT * FROM " . $from_posts_table . " WHERE post_type = 'attachment' AND guid NOT IN ( SELECT guid FROM " . $to_posts_table .")";
      echo "<br>Trying to sync <b>" . $from_posts_table . "</b> with <b>" . $to_posts_table . "</b><br>";
      
      if ($posts = $wpdb->get_results($query)) :
          echo "Syncing ". count($posts) . " post(s).<br>";
          
          foreach ($posts as $post):
              
              switch_to_blog($from_blog_id);
              
              $post_ID = $post->ID;
              
              $attachment_post = get_post( $post_ID);
              
              $post_name = $attachment_post->post_name;
              $file_name = get_post_meta ( $post_ID, '_wp_attached_file')[0];
              
              //get relevant post meta -> since array get's returned, only get first part
              $meta_array = array(
                    '_wp_attached_file' => get_post_meta ( $post_ID, '_wp_attached_file')[0],
                    '_wp_attachment_metadata' => get_post_meta ( $post_ID, '_wp_attachment_metadata')[0],
                    '_wp_attachment_backup_sizes' => get_post_meta ( $post_ID, '_wp_attachment_backup_sizes')[0],
                    '_wp_attachment_image_alt' => get_post_meta ( $post_ID, '_wp_attachment_image_alt')[0]
                );
              //exclude null values
              $meta_array_not_null = array_filter($meta_array, function($var){return !is_null($var);} );
              
              restore_current_blog();
              
              switch_to_blog($to_blog_id);
              
              $my_post = array(
                'post_author'    => $attachment_post->post_author,
                'post_title'     => $attachment_post->post_title,
                'post_content'   => $attachment_post->post_content,
                'post_name'      => $attachment_post->post_name,
                'post_excerpt'   => $attachment_post->post_excerpt,
                'guid'           => $attachment_post->guid,
                'post_type'      => 'attachment',
                'post_mime_type' => $attachment_post->post_mime_type,
                'meta_input'     => $meta_array_not_null
              );
              
              // unhook this function so it doesn't loop infinitely
          		remove_action( 'added_post_meta', $MultisiteMediaSync );
          
          		// update the post, which calls save_post again
              wp_insert_post( $my_post );
              
              echo "New post created successfully. (ID: $post_name / Image: $file_name)<br>";
              
          		// re-hook this function
          		add_action( 'added_post_meta', $MultisiteMediaSync );
              
              restore_current_blog();

      
          endforeach;
          
      else:
        echo "Nothing to Sync<br>";
      endif;
    
    }

    // Call postSync for each Blog    
    foreach(msms_getBlogs() as $blog) {
      $from_blog_id = $blog->blog_id;
      foreach(msms_getBlogs() as $blog) {
        if($blog->blog_id != $from_blog_id) {
          $to_blog_id = $blog->blog_id;
          postSync($from_blog_id, $to_blog_id);
        }
      }
    }	
  
    //Info
  	echo "<br><hr/><br><b>Multisite Mediasync by Sean Grünböck @ <a href='http://handschlag.io' target='_blank'><img src='" . plugin_dir_url( __FILE__ ) . "assets/handschlag-logo-color_130@2x.png' width='130' style='vertical-align:bottom;'></a></b>";
  
  	} // End Management Page

}; // End Multisite Sync Class


add_action( 'plugins_loaded', create_function( '', 'global $MultisiteMediaSync; $MultisiteMediaSync = new MultisiteMediaSync();' ) );

//NEW ATTACHMENT UPlOADED 

function multisite_mediasync_add_attachment_meta($meta_id, $post_ID, $meta_key, $_meta_value) {
  if($meta_key == '_wp_attachment_metadata') {
  
    $attachment_post = get_post( $post_ID );
    
    //get relevant post meta -> since array get's returned, only get first part
    $attachment_post_meta_wp_attached_file = get_post_meta ( $post_ID, '_wp_attached_file')[0];
    $attachment_post_meta_wp_attachment_metadata = get_post_meta ( $post_ID, '_wp_attachment_metadata')[0];
    
    global $wpdb;
    
    foreach(msms_getBlogs() as $blog) {
      if($blog->blog_id != get_current_blog_id()) {
        switch_to_blog($blog->blog_id);
        
        $my_post = array(
          'post_author'    => $attachment_post->post_author,
          'post_title'     => $attachment_post->post_title,
          'post_content'   => $attachment_post->post_content,
          'post_name'      => $attachment_post->post_name,
          'post_excerpt'   => $attachment_post->post_excerpt,
          'guid'           => $attachment_post->guid,
          'post_type'      => 'attachment',
          'post_mime_type' => $attachment_post->post_mime_type,
          'meta_input'     => array(
              '_wp_attached_file' => $attachment_post_meta_wp_attached_file,
              '_wp_attachment_metadata' => $attachment_post_meta_wp_attachment_metadata
          )
        );
        
        // unhook this function so it doesn't loop infinitely
    		remove_action( 'added_post_meta', 'multisite_mediasync_add_attachment_meta' );
    
    		// update the post, which calls save_post again
    		//wp_update_post( array( 'ID' => $post_ID, 'post_status' => 'private' ) );
        wp_insert_post( $my_post );
    
    		// re-hook this function
    		add_action( 'added_post_meta', 'multisite_mediasync_add_attachment_meta' );
        
        restore_current_blog();
      }
    }
    
  }
}

add_action( 'added_post_meta', 'multisite_mediasync_add_attachment_meta', 10, 4);

// ATTACHMENT DELETED:
// hook: delete_attachment
function multisite_mediasync_delete_attachment($post_ID) {
  
  $attachment_post = get_post( $post_ID );

  foreach(msms_getBlogs() as $blog) {
    if($blog->blog_id != get_current_blog_id()) {
      switch_to_blog($blog->blog_id);
      
      //write_log(msms_getIDfromGUID($attachment_post->guid));

      // unhook this function so it doesn't loop infinitely
  		remove_action( 'delete_attachment', 'multisite_mediasync_delete_attachment' );

      wp_delete_attachment(msms_getIDfromGUID($attachment_post->guid));
      
  		// re-hook this function
  		add_action( 'delete_attachment', 'multisite_mediasync_delete_attachment' );
      
      restore_current_blog();
    }
  }           
    
}

add_action( 'delete_attachment', 'multisite_mediasync_delete_attachment');

// ATTACHMENT UPDATE
// hook: attachment_updated

function multisite_mediasync_update_attachment_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
  write_log ( 'meta updated: ' . $meta_id . "/" . $object_id . "/" . $meta_key . "/" . $_meta_value);
  
  if($meta_key == '_wp_attached_file' || $meta_key == '_wp_attachment_metadata' || $meta_key == '_wp_attachment_backup_sizes') {
  
    $attachment_post = get_post( $object_id );
  
    foreach(msms_getBlogs() as $blog) {
      switch_to_blog($blog->blog_id);
      
      // unhook this function so it doesn't loop infinitely
  		remove_action( 'updated_postmeta', 'multisite_mediasync_update_attachment_meta' );
  		
      update_metadata('post', msms_getIDfromGUID($attachment_post->guid), $meta_key, $_meta_value);
      
  		// re-hook this function
  		add_action( 'updated_postmeta', 'multisite_mediasync_update_attachment_meta' );
      
      restore_current_blog();      
    }
  
  }
}

add_action( 'updated_post_meta', 'multisite_mediasync_update_attachment_meta', 10, 4);

/**
 * Force all network uploads to reside in "wp-content/uploads", and by-pass
 * "files" URL rewrite for site-specific directories.
 * 
 * @link    http://wordpress.stackexchange.com/q/147750/1685
 * 
 * @param   array   $dirs
 * @return  array
 */

function multisite_mediasync_single_upload_dir( $dirs ) {
    $dirs['baseurl'] = network_site_url( '/wp-content/uploads' );
    $dirs['basedir'] = ABSPATH . 'wp-content/uploads';
    $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
    $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];

    return $dirs;
}

add_filter( 'upload_dir', 'multisite_mediasync_single_upload_dir' );
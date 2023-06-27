<?php

/**
 * Class to access Meetup CPT posts via the v2 REST API.
 *
 * @see WP_REST_Posts_Controller
 */
class WordCamp_REST_Meetups_Controller extends WP_REST_Posts_Controller {
 /**
  * Retrieves the Meetup post's schema, conforming to JSON Schema.
  *
  * Meetup-specific modifications to the standard post schema.
  *
  * @access public
  *
  * @return array Item schema data.
  */
 public function get_item_schema() {
   $schema = parent::get_item_schema();

   // Since there is more than one public post status, show it in REST response
   if ( false === array_search( 'view', $schema['properties']['status']['context'] ) ) {
     $schema['properties']['status']['context'][] = 'view';
   }

   return $this->add_additional_fields_schema( $schema );
 }

 /**
  * Sanitizes and validates the list of post statuses, including whether the
  * user can query private statuses.
  *
  * Based on the method in WP_REST_Posts_Controller, but takes into account that
  * there are multiple public statuses for the Meetup CPT.
  *
  * @access public
  *
  * @param  string|array    $statuses  One or more post statuses.
  * @param  WP_REST_Request $request   Full details about the request.
  * @param  string          $parameter Additional parameter to pass to validation.
  * @return array|WP_Error A list of valid statuses, otherwise WP_Error object.
  */
 public function sanitize_post_statuses( $statuses, $request, $parameter ) {
   $statuses = wp_parse_slug_list( $statuses );

   $public_statuses = Meetup_Loader::get_public_post_statuses();

   foreach ( $statuses as $status ) {
     if ( in_array( $status, $public_statuses ) ) {
       continue;
     }

     $post_type_obj = get_post_type_object( $this->post_type );

     if ( current_user_can( $post_type_obj->cap->edit_posts ) ) {
       $result = rest_validate_request_arg( $status, $request, $parameter );
       if ( is_wp_error( $result ) ) {
         return $result;
       }
     } else {
       return new WP_Error( 'rest_forbidden_status', __( 'Status is forbidden.' ), array( 'status' => rest_authorization_required_code() ) );
     }
   }

   return $statuses;
 }

 /**
  * Checks if user can read the Meetup post.
  *
  * First make our custom check against public Meetup statuses and
  * after that fallback to default WP_REST_Posts_Controller for assurance.
  *
  * @access public
  *
  * @param object $post Post object.
  * @return bool Whether the post can be read.
  */
 public function check_read_permission( $post ) {
   $public_statuses = Meetup_Loader::get_public_post_statuses();

   // If post status is not listed as public, it cannot be read
   if ( ! in_array( $post->post_status, $public_statuses ) ) {
     return false;
   }

   // Fallback to default read permission check
   return WP_REST_Posts_Controller::check_read_permission( $post );
 }
}

<?php

class GPNF_GravityFlow {

	private static $instance = null;

	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	private function __construct() {

		if ( ! class_exists( 'Gravity_Flow' ) ) {
			return;
		}

		add_filter( 'gpnf_can_user_edit_entry', array( $this, 'can_user_edit_entry' ), 10, 3 );
		add_filter( 'gpnf_submitted_entry_ids', array( $this, 'get_submitted_entry_ids' ), 10, 3 );
		add_filter( 'gravityflow_permission_granted_entry_detail', array( $this, 'can_user_view_child_entry' ), 10, 4 );
		add_filter( 'gform_field_input', array( $this, 'enable_editing_pricing_field' ), 10, 5 );
		add_action( 'gravityflow_pre_restart_workflow', array( $this, 'process_restart_workflow' ), 10, 2 );

	}

	/**
	 * Gets the current Gravity Flow form ID from query params.
	 *
	 * @return mixed
	 */
	public function get_current_flow_form_id() {
		return rgget( 'id' );
	}

	/**
	 * Gets the current Gravity Flow entry ID from query params.
	 *
	 * @return mixed
	 */
	public function get_current_flow_entry_id() {
		return rgget( 'lid' );
	}

	/**
	 * Ensures that the current user has permission to edit the nested form in the parent entry via workflow.
	 *
	 * @param Gravity_Flow_Step Current Step.
	 * @param int Current Nested Form Field ID being checked.
	 *
	 * @return bool
	 */
	public function can_user_edit_parent_entry( $step, $nested_form_field_id ) {
		if ( ! method_exists( $step, 'get_editable_fields' ) ) {
			return false;
		}

		return in_array( $nested_form_field_id, $step->get_editable_fields(), false );
	}

	/**
	 * Handle adding permissions to edit child entries for the current entry being processed in a workflow.
	 *
	 * @param bool     $can_user_edit_entry Can the current user edit the given entry?
	 * @param array    $entry               Current entry.
	 * @param \WP_User $user                Current user.
	 *
	 * @return bool
	 *
	 */
	public function can_user_edit_entry( $can_user_edit_entry, $entry, $user ) {
		$parent_form_id  = rgar( $entry, 'gpnf_entry_parent_form' );
		$parent_entry_id = rgar( $entry, 'gpnf_entry_parent' );

		if ( ! $parent_form_id || ! $parent_entry_id ) {
			return $can_user_edit_entry;
		}

		$parent_form  = GFAPI::get_form( $parent_form_id );
		$parent_entry = GFAPI::get_entry( $parent_entry_id );

		if ( ! $parent_form || is_wp_error( $parent_entry ) ) {
			return $can_user_edit_entry;
		}

		$current_step       = gravity_flow()->get_current_step( $parent_form, $parent_entry );
		$flow_child_entries = $this->get_current_workflow_child_entries( $parent_form, $parent_entry );

		foreach ( $flow_child_entries as $nested_form_field_id => $child_entry_ids ) {
			if ( ! $this->can_user_edit_parent_entry( $current_step, $nested_form_field_id ) ) {
				continue;
			}

			if ( in_array( $entry['id'], $child_entry_ids, false ) ) {
				return true;
			}
		}

		return $can_user_edit_entry;
	}

	/**
	 * Returns an associative array containing all the child entries associated with the parent entry going through
	 * a user input Gravity Flow workflow.
	 *
	 * @param array $parent_form  Form
	 * @param array $parent_entry Entry
	 *
	 * @return array
	 */
	public function get_current_workflow_child_entries( $parent_form, $parent_entry ) {
		$flow_child_entry_ids = array();

		if ( empty( $parent_form['fields'] ) ) {
			return $flow_child_entry_ids;
		}

		$current_step = gravity_flow()->get_current_step( $parent_form, $parent_entry );

		if ( $current_step && ( $current_step->get_type() === 'user_input' || $current_step->get_type() === 'approval' ) ) {
			foreach ( $parent_form['fields'] as $field ) {
				if ( $field->get_input_type() == 'form' ) {
					if ( ! $this->can_user_edit_parent_entry( $current_step, $field->id ) ) {
						continue;
					}

					$flow_child_entry_ids[ $field->id ] = gp_nested_forms()->get_child_entry_ids_from_value( gp_nested_forms()->get_field_value( $parent_form, $parent_entry, $field->id ) );
				}
			}
		}

		return $flow_child_entry_ids;
	}

	/**
	 * Add in the child entry IDs from the parent form that's being processed.
	 *
	 * @param array                 $entry_ids Entry IDs to populate the field with.
	 * @param array                 $form      Current form object.
	 * @param \GP_Nested_Form_Field $field     Current field object.
	 *
	 * @return array
	 */
	public function get_submitted_entry_ids( $entry_ids, $form, $field ) {
		/* Ensure workflow form ID matches the parent form ID being filtered. */
		if ( $form['id'] != $this->get_current_flow_form_id() ) {
			return $entry_ids;
		}

		if ( ! $this->get_current_flow_entry_id() ) {
			return $entry_ids;
		}

		$current_flow_entry = GFAPI::get_entry( $this->get_current_flow_entry_id() );

		if ( is_wp_error( $current_flow_entry ) ) {
			return $entry_ids;
		}

		$flow_child_entries = $this->get_current_workflow_child_entries( $form, $current_flow_entry );

		if ( empty( $flow_child_entries[ $field->id ] ) ) {
			return $entry_ids;
		}

		return array_unique( array_merge( $entry_ids, $flow_child_entries[ $field->id ] ) );
	}

	/**
	 * Check if we are testing on a child entry which already has permission for its parent.
	 *
	 * @param array                 $entry_ids Entry IDs to populate the field with.
	 * @param array                 $form      Current form object.
	 * @param \GP_Nested_Form_Field $field     Current field object.
	 *
	 * @return array
	 */
	public function can_user_view_child_entry( $permission_granted, $entry, $form, $current_step ) {

		// If permission granted is already true, return.
		if ( $permission_granted ) {
			return $permission_granted;
		}

		$parent_entry_id = rgar( $entry, 'gpnf_entry_parent' );

		// Not a child entry, return back with original permission status
		if ( empty( $parent_entry_id ) ) {
			return $permission_granted;
		}

		// Get the parent entry, form, and step.
		$parent_entry     = GFAPI::get_entry( $parent_entry_id );
		$parent_form      = GFAPI::get_form( $parent_entry['form_id'] );
		$gravity_flow_api = new Gravity_Flow_API( $parent_form['id'] );
		$parent_step      = $gravity_flow_api->get_current_step( $parent_entry );

		// Check if the parent entry was granted the "view" permission.
		$permission_granted = Gravity_Flow_Entry_Detail::is_permission_granted( $parent_entry, $parent_form, $parent_step );

		// If parent was granted "view" permission, child is also granted it.
		return $permission_granted;
	}

	/**
	 * Force pricing fields to show in child forms when editing on Gravity Flow pages.
	 *
	 * If `$_GET['view'] == 'entry'`, Gravity Forms will show "Pricing fields are not editable."
	 *
	 * @param string                $input    Input tag string.
	 * @param \GP_Nested_Form_Field $field    Current field object.
	 * @param string                $value    Pre-populated value.
	 * @param int                   $entry_id Current entry id.
	 * @param int                   $form_id  Current form id.
	 *
	 * @return string
	 */
	public function enable_editing_pricing_field( $input, $field, $value, $entry_id, $form_id ) {

		if ( ! empty( $input ) || rgget( 'view' ) !== 'entry' ) {
			return $input;
		}

		// Gravity Forms by default displays "Pricing fields are not editable" which can be overriden if we provide the field input for it.
		if ( GFCommon::is_pricing_field( $field->type ) && wp_verify_nonce( rgpost( 'gpnf_edit_entry_submission' ), 'gpnf_edit_entry_submission_' . $form_id ) ) {
			$form  = GFAPI::get_form( $form_id );
			$entry = GFAPI::get_entry( $entry_id );
			return $field->get_field_input( $form, $value, $entry );
		}

		return $input;
	}

	/**
	 * Ensure to handle the parent entry submission when restarting a workflow.
	 *
	 * @param array $entry Current entry.
	 * @param array $form  Current form object.
	 */
	public function process_restart_workflow( $entry, $form ) {
		gp_nested_forms()->handle_parent_submission( $entry, $form );
	}

}

function gpnf_gravityflow() {
	return GPNF_GravityFlow::get_instance();
}

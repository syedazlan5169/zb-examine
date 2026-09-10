<?php

return [

    'title' => 'Examination Submission',
    'navigation' => 'Submissions',

    'staff' => [
        'list_title' => 'Examinations',
        'detail_title' => 'Examination Details',
        'select_examination' => 'Select an examination to view details.',
        'search' => 'Search examinations',
        'search_placeholder' => 'Submission number, customs form, agent or company',
        'submitted_at' => 'Submitted at',
        'agent' => 'Agent',
        'company' => 'Company',
        'agent_code' => 'Agent code',
        'station_code' => 'Station code',
        'photos' => 'Photos',
        'photo_count' => '{1} :count photo|[2,*] :count photos',
        'view' => 'View',
        'customs_forms' => 'Customs Form Numbers',
        'evidence' => 'Evidence Photos',
        'no_examinations' => 'No examinations have been recorded.',
        'no_search_results' => 'No examinations match your search.',
        'no_photos' => 'No evidence photos.',
        'no_customs_forms' => 'No customs form numbers.',
        'back_to_list' => 'Back to list',
        'previous' => 'Previous',
        'next' => 'Next',
        'page' => 'Page :current of :last',
    ],

    'sections' => [
        'submission' => 'Submission',
        'agent_information' => 'Agent Information',
        'examination_information' => 'Examination Information',
    ],

    'fields' => [
        'submission_number' => 'Submission Number',
        'agent_name' => 'Agent Name',
        'agent_phone' => 'Agent Phone Number',
        'agent_code' => 'Agent Code',
        'agent_company_name' => 'Agent Company Name',
        'agent_station_code' => 'Agent Station Code',
        'location' => 'Examination Location',
        'form_type' => 'Form Type',
        'form_type_other' => 'Specify Other Form Type',
        'customs_form_numbers' => 'Customs Form Number',
        'container_status' => 'Container Status',
        'reason' => 'Reason (Optional)',
        'reason_other' => 'Specify Other Reason',
        'attending_officer_type' => 'Attending Officer Type',
    ],

    'help' => [
        'customs_form_numbers' => 'Enter each customs form number separately.',
    ],

    'reason_placeholder' => 'No specific reason',
    'choose_option' => 'Please select…',

    'options' => [
        'location' => [
            'container_gate_terminal' => 'Container Gate Terminal',
            'conventional_gate' => 'Conventional Gate',
        ],
        'form_type' => [
            'k1' => 'Customs 1 (K1)',
            'k2' => 'Customs 2 (K2)',
            'k3' => 'Customs 3 (K3)',
            'k8' => 'Customs 8 (K8)',
            'attachment_a' => 'Lampiran A (Tarik Balik)',
            'ucustoms' => 'uCustoms',
            'ata_carnet' => 'ATA Carnet',
            'other' => 'Other',
        ],
        'container_status' => [
            'fcl' => 'FCL',
            'lcl' => 'LCL',
            'conventional' => 'Conventional',
        ],
        'reason' => [
            'assessing_officer_instruction' => 'Assessing Officer Instruction',
            'drawback' => 'Drawback',
            'export_cancelled' => 'Export Cancelled',
            'transfer_k8' => 'Transfer (K8)',
            'disposal' => 'Disposal',
            'ata_carnet' => 'ATA Carnet',
            'temporary_import' => 'Temporary Import',
            'temporary_export' => 'Temporary Export',
            'other' => 'Other',
        ],
        'attending_officer_type' => [
            'customs' => 'Customs',
            'swcorps' => 'SWCorps',
        ],
    ],

    'customs_form_numbers' => [
        'add' => 'Add',
        'remove' => 'Remove',
        'duplicate' => 'This customs form number has already been added.',
    ],

    'errors' => [
        'validation_summary' => 'Please check the form for errors below.',
        'customs_form_numbers' => [
            'empty_input' => 'Please enter at least one customs form number.',
            'empty_token' => 'One of the customs form numbers is blank. Please fill it in or remove the row.',
            'value_too_long' => 'One of the customs form numbers is too long (maximum 100 characters).',
            'duplicate_number' => 'This customs form number has already been added.',
            'generic' => 'The customs form numbers could not be processed. Please check your entry.',
        ],
        'sequence_exhausted' => 'The system has reached its submission limit for today. Please try again later.',
        'generic_failure' => 'Something went wrong while submitting the examination. Please try again.',
    ],

    'actions' => [
        'submit' => 'Submit Examination',
        'submitting' => 'Submitting…',
        'new_submission' => 'New Examination',
        'copy_number' => 'Copy Number',
        'copied' => 'Copied!',
    ],

    'success' => [
        'heading' => 'Examination Submitted',
        'message' => 'Your examination has been successfully registered. Please keep the submission number below for reference.',
        'submission_number_label' => 'Submission Number',
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Only the rule keys actually used by this application are translated
    | here. Any key not defined below falls back to lang/en/validation.php
    | per-key, per Laravel's translator fallback behaviour.
    |
    */

    'required' => 'Medan :attribute wajib diisi.',
    'string' => 'Medan :attribute mestilah rentetan teks.',
    'max' => [
        'string' => 'Medan :attribute tidak boleh melebihi :max aksara.',
    ],
    'required_if' => 'Medan :attribute wajib diisi apabila :other ialah :value.',
    'enum' => ':attribute yang dipilih tidak sah.',

    'attributes' => [
        'agent_name' => 'nama ejen',
        'agent_phone' => 'nombor telefon ejen',
        'agent_code' => 'kod ejen',
        'agent_company_name' => 'nama syarikat ejen',
        'agent_station_code' => 'kod stesen ejen',
        'location' => 'lokasi pemeriksaan',
        'form_type' => 'jenis borang',
        'form_type_other' => 'jenis borang lain',
        'customs_form_numbers' => 'nombor borang kastam',
        'container_status' => 'status kontena',
        'reason' => 'sebab pemeriksaan',
        'reason_other' => 'sebab lain',
        'attending_officer_type' => 'jenis pegawai bertugas',
    ],

];

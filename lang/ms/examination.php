<?php

return [

    'title' => 'Pendaftaran Pemeriksaan',

    'sections' => [
        'agent_information' => 'Maklumat Ejen',
        'examination_information' => 'Maklumat Pemeriksaan',
    ],

    'fields' => [
        'agent_name' => 'Nama Ejen',
        'agent_phone' => 'Nombor Telefon Ejen',
        'agent_code' => 'Kod Ejen',
        'agent_company_name' => 'Nama Syarikat Ejen',
        'agent_station_code' => 'Kod Stesen Ejen',
        'location' => 'Lokasi Pemeriksaan',
        'form_type' => 'Jenis Borang',
        'form_type_other' => 'Nyatakan Jenis Borang Lain',
        'customs_form_numbers' => 'NOMBOR BORANG KASTAM',
        'container_status' => 'Status Kontena',
        'reason' => 'Sebab (Pilihan)',
        'reason_other' => 'Nyatakan Sebab Lain',
        'attending_officer_type' => 'Jenis Pegawai Bertugas',
    ],

    'help' => [
        'customs_form_numbers' => 'Masukkan setiap nombor borang kastam secara berasingan.',
    ],

    'reason_placeholder' => 'Tiada sebab khusus',
    'choose_option' => 'Sila pilih…',

    'options' => [
        'location' => [
            'container_gate_terminal' => 'TERMINAL GATE KONTENA',
            'conventional_gate' => 'GATE CONVENTIONAL',
        ],
        'form_type' => [
            'k1' => 'KASTAM 1 (K1)',
            'k2' => 'KASTAM 2 (K2)',
            'k3' => 'KASTAM 3 (K3)',
            'k8' => 'KASTAM 8 (K8)',
            'attachment_a' => 'Lampiran A (Tarik Balik)',
            'ucustoms' => 'uCUSTOMS',
            'ata_carnet' => 'ATA CARNET',
            'other' => 'Lain-lain',
        ],
        'container_status' => [
            'fcl' => 'FCL',
            'lcl' => 'LCL',
            'conventional' => 'CONVENTIONAL',
        ],
        'reason' => [
            'assessing_officer_instruction' => 'ARAHAN PEGAWAI PENAKSIR',
            'drawback' => 'DRAWBACK',
            'export_cancelled' => 'EKSPORT DIBATALKAN',
            'transfer_k8' => 'PEMINDAHAN (K8)',
            'disposal' => 'PELUPUSAN',
            'ata_carnet' => 'ATA CARNET',
            'temporary_import' => 'IMPORT SEMENTARA',
            'temporary_export' => 'EKSPORT SEMENTARA',
            'other' => 'Lain-lain',
        ],
        'attending_officer_type' => [
            'customs' => 'KASTAM',
            'swcorps' => 'SWCORPS',
        ],
    ],

    'customs_form_numbers' => [
        'add' => 'Tambah',
        'remove' => 'Buang',
        'duplicate' => 'Nombor borang kastam ini telah ditambah.',
    ],

    'errors' => [
        'validation_summary' => 'Sila semak borang untuk ralat di bawah.',
        'customs_form_numbers' => [
            'empty_input' => 'Sila masukkan sekurang-kurangnya satu nombor borang kastam.',
            'empty_token' => 'Salah satu nombor borang kastam kosong. Sila isikan atau buang baris tersebut.',
            'value_too_long' => 'Salah satu nombor borang kastam terlalu panjang (maksimum 100 aksara).',
            'duplicate_number' => 'Nombor borang kastam ini telah ditambah.',
            'generic' => 'Nombor borang kastam tidak dapat diproses. Sila semak input anda.',
        ],
        'sequence_exhausted' => 'Sistem telah mencapai had penyerahan bagi hari ini. Sila cuba lagi kemudian.',
        'generic_failure' => 'Berlaku masalah semasa menghantar pemeriksaan. Sila cuba lagi.',
    ],

    'actions' => [
        'submit' => 'Hantar Pemeriksaan',
        'submitting' => 'Menghantar…',
        'new_submission' => 'Daftar Pemeriksaan Baru',
        'copy_number' => 'Salin Nombor',
        'copied' => 'Disalin!',
    ],

    'success' => [
        'heading' => 'Pemeriksaan Berjaya Dihantar',
        'message' => 'Pemeriksaan anda telah berjaya didaftarkan. Sila simpan nombor penyerahan di bawah untuk rujukan.',
        'submission_number_label' => 'Nombor Penyerahan',
    ],

];

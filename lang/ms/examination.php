<?php

return [

    'title' => 'Pendaftaran Pemeriksaan',
    'navigation' => 'Pemeriksaan',

    'staff' => [
        'list_title' => 'Senarai Pemeriksaan',
        'detail_title' => 'Butiran Pemeriksaan',
        'select_examination' => 'Pilih pemeriksaan untuk melihat butiran.',
        'search' => 'Cari pemeriksaan',
        'search_placeholder' => 'Nombor penyerahan, borang kastam, ejen atau syarikat',
        'submitted_at' => 'Tarikh dan masa penyerahan',
        'agent' => 'Ejen',
        'company' => 'Syarikat',
        'agent_code' => 'Kod ejen',
        'station_code' => 'Kod stesen',
        'photos' => 'Foto',
        'photo_count' => '{1} :count foto|[2,*] :count foto',
        'view' => 'Lihat',
        'customs_forms' => 'Nombor Borang Kastam',
        'evidence' => 'Bukti Foto',
        'no_examinations' => 'Tiada pemeriksaan direkodkan.',
        'no_search_results' => 'Tiada pemeriksaan sepadan dengan carian anda.',
        'no_photos' => 'Tiada foto bukti.',
        'no_customs_forms' => 'Tiada nombor borang kastam.',
        'back_to_list' => 'Kembali ke senarai',
        'previous' => 'Sebelumnya',
        'next' => 'Seterusnya',
        'page' => 'Halaman :current daripada :last',
    ],

    'agent' => [
        'navigation' => 'Penyerahan Saya',
        'list_title' => 'Penyerahan Saya',
        'detail_title' => 'Butiran Penyerahan',
        'empty_state' => 'Tiada penyerahan ditemui.',
        'back_to_list' => 'Kembali ke penyerahan',
    ],

    'sections' => [
        'submission' => 'Penyerahan',
        'agent_information' => 'Maklumat Ejen',
        'examination_information' => 'Maklumat Pemeriksaan',
    ],

    'fields' => [
        'submission_number' => 'Nombor Penyerahan',
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

<?php

return [
    'wmsu_email_domain' => env('WMSU_EMAIL_DOMAIN', 'wmsu.edu.ph'),
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'),
    'cor_max_file_size_kilobytes' => (int) env('COR_MAX_FILE_SIZE_KILOBYTES', 10240),
    'listing_image_disk' => env('LISTING_IMAGE_DISK', 'public'),
];

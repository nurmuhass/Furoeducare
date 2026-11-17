<?php

declare(strict_types=1);

return [
    '/digital-library' => [
        'title' => 'Digital library',
        'category' => 'Learning resources',
        'icon' => 'book',
        'tone' => 'sage',
        'lead' => 'Discover free books and education resources for teaching, study and independent learning. The library will bring published downloads together in one place.',
        'emptyTitle' => 'Downloads will appear here',
        'emptyText' => 'The public library is awaiting its first published titles. Free books can be downloaded without an account once they are listed.',
        'next' => ['/services', 'Explore other services'],
        'section' => 'Using the library',
        'details' => [
            ['Free public books', 'Published free titles are available to visitors without registration.'],
            ['Teaching and learning', 'The library is intended to support lesson preparation, classroom teaching and independent reading.'],
            ['Managed access', 'Furoeducare manages publication and access to library items. A listing will explain the access available for that item.'],
        ],
    ],
    '/training-workshops' => [
        'title' => 'Training and workshops',
        'category' => 'Professional development',
        'icon' => 'workshop',
        'tone' => 'clay',
        'lead' => 'Explore professional development for schools and education teams. Tell Furoeducare what your staff want to learn and the team can help coordinate the request.',
        'emptyTitle' => 'No workshops are listed yet',
        'emptyText' => 'Published workshop dates will appear here. You can preview the contact form for general training enquiries.',
        'next' => ['/contact', 'Go to contact'],
        'section' => 'Planning a school workshop',
        'details' => [
            ['Describe your training needs', 'Identify the staff group, the skills you want to develop and what would help in your classrooms.'],
            ['Share practical details', 'Consider participant numbers, a preferred timeframe and how the training should be delivered.'],
            ['Coordinate with Furoeducare', 'The Furoeducare team manages school training and workshop requests.'],
        ],
    ],
];

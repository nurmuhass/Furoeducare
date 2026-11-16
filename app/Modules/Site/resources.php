<?php

declare(strict_types=1);

return [
    '/find-teachers' => [
        'title' => 'Find teachers',
        'category' => 'For schools and families',
        'icon' => 'people',
        'tone' => 'green',
        'lead' => 'Explore teaching and tutoring support for your school or family. Public profiles will help you understand a teacher’s subjects, experience and professional background.',
        'emptyTitle' => 'Teacher profiles will appear here',
        'emptyText' => 'There are no public profiles to browse yet. Learn how Furoeducare handles teacher requests and professional information.',
        'next' => ['/services#tutoring', 'Explore recruitment and tutoring'],
        'section' => 'What you’ll be able to do',
        'details' => [
            ['Browse public profiles', 'View approved profiles without creating an account. Each listing shares a limited set of professional fields.'],
            ['Request teaching support', 'Schools and parents can use an account to ask about a teacher or request more information. Parents can also make a private tutoring request.'],
            ['Work with Furoeducare', 'Furoeducare manages enquiries, shortlisting and access to original CVs. Public listings keep contact details and private documents out of view.'],
        ],
    ],
    '/teaching-jobs' => [
        'title' => 'Teaching jobs',
        'category' => 'For teachers and NYSC members',
        'icon' => 'case',
        'tone' => 'sand',
        'lead' => 'Explore teaching opportunities from approved schools and institutions in Nigeria. Furoeducare coordinates the recruitment process from application to placement.',
        'emptyTitle' => 'There are no vacancies to browse yet',
        'emptyText' => 'Open teaching vacancies will be listed here. You can explore the teacher account entry in the meantime.',
        'next' => ['/register', 'Explore account options'],
        'section' => 'How teaching recruitment works',
        'details' => [
            ['Build a professional profile', 'Teachers and NYSC members can prepare a profile that describes their subjects, qualifications and experience.'],
            ['Apply for a suitable role', 'Applications can be submitted before professional verification. Furoeducare manages shortlisting and follow-up.'],
            ['Complete verification', 'Final placement requires professional verification. Approved schools can post vacancies through their accounts.'],
        ],
    ],
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

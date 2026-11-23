<?php
declare(strict_types=1);
return [
    'up' => "INSERT INTO staff_roles (slug, name, built_in) VALUES ('super_admin','Super Admin',1),('recruitment_officer','Recruitment Officer',1),('verification_officer','Verification Officer',1),('library_manager','Library Manager',1),('training_coordinator','Training Coordinator',1)",
    'down' => "DELETE FROM staff_roles WHERE built_in = 1",
];

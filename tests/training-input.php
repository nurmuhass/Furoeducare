<?php

declare(strict_types=1);

use Furoeducare\Modules\Training\TrainingInput;
use Furoeducare\Modules\Training\TrainingWorkflow;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$module = ['title' => 'Inclusive teaching', 'description' => 'Practical classroom approaches.', 'category' => 'Teaching',
    'objectives' => 'Plan inclusive lessons.', 'target_participants' => 'Teaching staff', 'duration' => 'One day',
    'delivery_format' => 'on_site'];
expect(TrainingInput::module($module)['title'] === $module['title'], 'Validate training module metadata.');
foreach ([['title' => ''], ['delivery_format' => 'paid'], ['description' => str_repeat('x', 6001)]] as $bad) {
    try {
        TrainingInput::module($bad + $module);
        throw new RuntimeException('Invalid module accepted.');
    } catch (DomainException $error) {
        expect($error->getCode() === 422, 'Invalid metadata must return validation errors.');
    }
}
expect(TrainingInput::ids(['3', '8']) === [3, 8], 'Module choices use positive identifiers.');
foreach ([['1', '1'], ['0'], array_fill(0, 21, '1')] as $bad) {
    try {
        TrainingInput::ids($bad);
        throw new RuntimeException('Invalid module selection accepted.');
    } catch (DomainException) {
    }
}
expect(TrainingWorkflow::allows('submitted', 'under_review') && TrainingWorkflow::allows('discussion', 'approve')
    && TrainingWorkflow::allows('scheduled', 'complete') && !TrainingWorkflow::allows('submitted', 'schedule')
    && !TrainingWorkflow::allows('completed', 'cancel'), 'Transitions follow the managed workflow.');
echo "PASS training metadata, module selection and workflow restrictions.\n";

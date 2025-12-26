<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\AiCommands\TUI\Ansi;
use BrainCLI\Console\AiCommands\TUI\Screen;
use BrainCLI\Console\Services\Ai;
use BrainCLI\Dto\Person;
use BrainCLI\Enums\Agent;

use Laravel\Prompts\Concerns\Colors;
use Laravel\Prompts\Concerns\Cursor;
use Laravel\Prompts\Concerns\Erase;
use Laravel\Prompts\Concerns\Events;
use Laravel\Prompts\Concerns\FakesInputOutput;
use Laravel\Prompts\Concerns\Fallback;
use Laravel\Prompts\Concerns\Interactivity;
use Laravel\Prompts\Concerns\Termwind;

use Laravel\Prompts\Concerns\Themes;

use Laravel\Prompts\Output\BufferedConsoleOutput;

use Laravel\Prompts\Prompt;

use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\search;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\form;

use function Laravel\Prompts\table;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\clear;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\spin;

use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Termwind\render;
use function Termwind\renderUsing;



class MeetingCommand extends CommandBridgeAbstract
{
    protected Ai $orbiter;

    protected array $signatureParts = [
        '{name : The name of the meeting}',
        '{--theme= : The theme of the meeting}',
        '{--t|task=* : The tasks for the meeting participants}',
    ];

    public function __construct() {
        $this->signature = "meeting";
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = "Start a meeting with AI agents";
        parent::__construct();
    }

    protected function handleBridge(): int|array
    {
        $this->selectOrbiter();

        $this->orbiter->ask();

        $else = $this->selectPerson('Some else');

        dd();


        $name = $this->argument('name');
        $theme = $this->option('theme') ?? 'General Discussion';
        $tasks = $this->option('task') ?: [];

        //intro($name);

        foreach (Agent::persons() as $person) {
            dump([
                'label' => $person->label(),
                'description' => $person->description(),
                'share' => $person->share(),
                'position' => $person->position(),
            ]);
        }

        return OK;
    }

    /**
     * Орбітр вибирається для керування зустріччю.
     *
     * Формат зустрічі:
     * 1. Визначення цілей зустрічі.
     * 2. Вибір орбітра.
     * 3. Вибір учасників.
     * 2. Представлення учасників, орбітра.
     *   2.1. Орбітр знайоиться з цілями зустрічі, учасниками (Він має по замовченю завжди знати Share кожного учасника і його опис для правильного вибору учасника).
     *   2.2. Обрані користувачем AI входять в курс справи та зберають необхідну інформацію по цілі зустрічі (Готуються).
     * 4. Проведення зустрічі.
     *   4.1. Орбітр відкриває зустріч, представляє цілі, учасників.
     *   4.2. Орбітр керує обговоренням, дає слово учасникам (користувач може в будьякий час втрутитися і задати питання або додати інформацію або змінити напрямок обговорення через орбітра).
     *   4.3. Користувач може казати тільки орбітру, тільки орбітр може давати слово іншим учасникам а також він може дати слово користувачеві.
     *   4.4. Остаточне слово за користувачем, він може в будь-який момент втрутитися в обговорення через орбітра. Користувач є головним і всі відхилення чи помилки в обговоренні мають бути поідомелні користувачеві. В будьяких ситуаціях в незрозумілих ситуаціях звертатись до користувача. Та і не забувати в цілому за користувача, завжди давати йому давати слово в важких та важливих питаннях.
     *   4.5. Орбітр підсумовує зустріч, визначає подальші кроки.
     *   4.6. Користувач затверджує підсумки зустрічі та подальші кроки.
     * 5. Після зустрічі.
     *   5.1. Орбітр готує протокол зустрічі та зберігає його.
     * 6. Завершення зустрічі.
     *
     *
     * Цілі орбітра:
     * 1. Координувати обговорення між учасниками.
     *   1.1. Забезпечити, щоб кожен учасник мав можливість висловитися.
     *   1.2. Грамотно давати слово учасникам.
     *   1.3. Підтримувати фокус на темі зустрічі.
     * 2. Підсумовувати ключові моменти обговорення.
     * 3. Визначати подальші кроки після зустрічі.
     * 4. Забезпечити, щоб користувач був в курсі всього, що відбувається.
     * 5. Враховувати інтереси та цілі кожного учасника
     *
     * @return void
     */
    protected function selectOrbiter(): void
    {
        $person = $this->selectPerson('Orbiter')
            ->identityDirection('')
            ->briefly();

        $this->orbiter = Ai::person($person);
    }

    protected function selectPerson(string $name): Person
    {
        return Agent::findPerson(search(
            label: "Select an AI $name",
            options: fn (string $value) => Agent::personList($value),
            placeholder: "Choose an $name...",
        ));
    }
}


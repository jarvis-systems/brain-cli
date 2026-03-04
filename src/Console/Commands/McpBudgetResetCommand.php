<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use BrainCore\Services\McpCall\McpCallBudget;

/**
 * MCP Budget Reset Command resets the persistent call budget.
 */
class McpBudgetResetCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:budget-reset
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'Reset the MCP call budget counter';

    /**
     * Handle the command execution.
     * 
     * @return int
     */
    public function handle(): int
    {
        try {
            $projectRoot = Brain::projectDirectory();
            $budget = McpCallBudget::create($projectRoot);

            $budget->reset();

            $this->outputResult([
                'ok' => true,
                'message' => 'MCP call budget has been reset.',
                'call_budget' => [
                    'limit' => $budget->getLimit(),
                    'remaining' => $budget->getRemaining(),
                    'storage_path' => $this->formatPath($budget->getStoragePath()),
                ],
            ]);

            return 0;
        } catch (\Throwable $e) {
            $this->outputError($e->getMessage(), 'BUDGET_RESET_FAILED');
            return 1;
        }
    }
}

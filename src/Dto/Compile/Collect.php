<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Compile;

use Bfg\Dto\Attributes\DtoItem;
use Bfg\Dto\Collections\DtoCollection;
use Bfg\Dto\Dto;

class Collect extends Dto
{
    /**
     * @param DtoCollection<int, Data> $agents
     * @param DtoCollection<int, Data> $commands
     * @param DtoCollection<int, Data> $mcp
     * @param DtoCollection<int, Data> $skills
     */
    public function __construct(
        #[DtoItem(Data::class)]
        public DtoCollection $agents,
        #[DtoItem(Data::class)]
        public DtoCollection $commands,
        #[DtoItem(Data::class)]
        public DtoCollection $mcp,
        #[DtoItem(Data::class)]
        public DtoCollection $skills,
        public Data $brain,
    ) {
    }
}

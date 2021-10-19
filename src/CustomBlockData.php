<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use InvalidArgumentException;
use InvalidStateException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use function explode;

final class CustomBlockData {

	private string $name; //TODO: FIXME

	public function __construct(
		private string $identifier,
		private int $legacyId,
		private CompoundTag $states
	) {
		try {
			CustomBlockLoader::getBlockRegistry()->validateBlockIdentifier($this->identifier);
		} catch (InvalidStateException $e) {
			throw new InvalidArgumentException($e->getMessage());
		}
		[, $this->name] = explode(":", $this->identifier);
	}

	public function getIdentifier() : string { return $this->identifier; }

	public function getName() : string { return $this->name; }

	public function getLegacyId() : int { return $this->legacyId; }

	public function getStates() : CompoundTag { return $this->states; }

	public function toBlockPaletteEntry() : BlockPaletteEntry {
		return new BlockPaletteEntry($this->identifier, $this->states);
	}

}
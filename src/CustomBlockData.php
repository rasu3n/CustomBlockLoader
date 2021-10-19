<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use InvalidArgumentException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use function preg_match;

final class CustomBlockData {

	public function __construct(
		private string $identifier,
		private int $legacyId,
		private CompoundTag $states
	) {
		if (preg_match("/^[a-zA-Z0-9._]+:[a-zA-Z0-9._]+$/", $this->identifier) !== 1) {
			throw new InvalidArgumentException("The identifier is invalid. The identifier must be of the form \"namespace:name\". (Only the characters \"a-zA-Z0-9._\" are allowed.)");
		}
	}

	public function getIdentifier() : string { return $this->identifier; }

	public function getLegacyId() : int { return $this->legacyId; }

	public function getStates() : CompoundTag { return $this->states; }

	public function toBlockPaletteEntry() : BlockPaletteEntry {
		return new BlockPaletteEntry($this->identifier, $this->states);
	}

}
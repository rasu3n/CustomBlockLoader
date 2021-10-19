<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

final class CustomBlockRegistry {

	/** @var array<string, CustomBlockData> identifier => CustomBlockData */
	private array $blocks = [];
	private bool $isChanged = false;

	public function add(CustomBlockData $data) : self {
		$this->blocks[$data->getIdentifier()] = $data;
		$this->isChanged = true;
		return $this;
	}

	public function get(string $identifier) : ?CustomBlockData {
		return $this->blocks[$identifier] ?? null;
	}

	public function remove(CustomBlockData $data) : self {
		if (isset($this->blocks[$data->getIdentifier()])) {
			unset($this->blocks[$data->getIdentifier()]);
			$this->isChanged = true;
		}
		return $this;
	}

	public function hasDirtyFlag() : bool {
		return $this->isChanged;
	}

	public function clearDirtyFlag() : void {
		$this->isChanged = false;
	}

	/** @return array<string, CustomBlockData> */
	public function getBlocks() : array {
		return $this->blocks;
	}

}
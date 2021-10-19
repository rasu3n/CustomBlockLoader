<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use InvalidStateException;
use function array_flip;
use function array_map;
use function explode;
use function preg_match;
use function var_dump;

final class CustomBlockRegistry {
	public const THE_IDENTIFIER_IS_INVALID = "The identifier is invalid.";

	/** @var array<string, CustomBlockData> identifier => CustomBlockData */
	private array $blocks = [];
	private bool $isChanged = false;
	/** @var array<string, int> */
	private array $invalidBlockNames;
	/** @var array<string, true> */
	private array $alreadyUsedNames = [];

	/**
	 * @param list<string> $invalidBlockNames
	 */
	public function __construct(array $invalidBlockNames = []) {
		$this->invalidBlockNames = array_flip(array_map(fn(string $identifier) => explode(":", $identifier)[1], $invalidBlockNames));
	}

	public function add(CustomBlockData $data) : self {
		if (isset($this->alreadyUsedNames[$data->getName()])) {
			throw new InvalidStateException(self::THE_IDENTIFIER_IS_INVALID . " Duplicate name \"{$data->getName()}\"");
		}
		$this->blocks[$data->getIdentifier()] = $data;
		$this->alreadyUsedNames[$data->getName()] = true;
		$this->isChanged = true;
		return $this;
	}

	public function get(string $identifier) : ?CustomBlockData {
		return $this->blocks[$identifier] ?? null;
	}

	public function remove(CustomBlockData $data) : self {
		if (isset($this->blocks[$data->getIdentifier()])) {
			unset($this->blocks[$data->getIdentifier()]);
			unset($this->alreadyUsedNames[$data->getName()]);
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

	/**
	 * @throws InvalidStateException
	 */
	public function validateBlockIdentifier(string $identifier) : void {
		if (preg_match("/^([a-zA-Z0-9._]+):([a-zA-Z0-9._]+)$/", $identifier, $matches) !== 1) {
			throw new InvalidStateException(self::THE_IDENTIFIER_IS_INVALID . " The identifier must be of the form \"namespace:name\". (Only the characters \"a-zA-Z0-9._\" are allowed.)");
		}

		[, $namespace, $name] = $matches;
		if ($namespace === "minecraft") {
			throw new InvalidStateException(self::THE_IDENTIFIER_IS_INVALID . " Invalid namespace \"$namespace\"");
		}
		if (isset($this->invalidBlockNames[$name])) {
			throw new InvalidStateException(self::THE_IDENTIFIER_IS_INVALID . " Invalid name \"$name\"");
		}
	}

}
<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use InvalidStateException;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use function array_map;
use function explode;
use function in_array;

class ValidateIdentifierTest extends TestCase {

	/** @var CustomBlockRegistry */
	private $registry;

	protected function setUp() : void {
		$this->registry = CustomBlockLoader::getBlockRegistry();
	}

	/**
	 * @return string[][]
	 */
	public function invalidFormatIdentifiers() : array {
		return [
			["test_block"],
			["test:"],
			[":test_block"],
			[":"],
			["test:test_block:"],
			["test:*aaa*"]
		];
	}

	/**
	 * @dataProvider invalidFormatIdentifiers
	 */
	public function testFormat(string $identifier) : void {
		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage("identifier must be");
		$this->registry->validateBlockIdentifier($identifier);
	}

	/**
	 * @return string[][]
	 */
	public function invalidNameIdentifiers() : array {
		/** @var string[] $vanillaNames */
		$vanillaNames = [];
		foreach (RuntimeBlockMapping::getInstance()->getBedrockKnownStates() as $state) {
			$name = explode(":", $state->getString("name"))[1];
			if (!in_array($name, $vanillaNames, true)) {
				$vanillaNames[] = $name;
			}
		}
		return array_map(fn(string $name) => ["test:$name"], $vanillaNames);
	}

	/**
	 * @dataProvider invalidNameIdentifiers
	 */
	public function testInvalidName(string $identifier) : void {
		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage("Invalid name");
		$this->registry->validateBlockIdentifier($identifier);
	}

	public function testDuplicateName() : void {
		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage("Duplicate");
		$this->registry->add(new CustomBlockData("test1:test_block", 0, CompoundTag::create()));
		$this->registry->add(new CustomBlockData("test2:test_block", 0, CompoundTag::create()));
	}

}
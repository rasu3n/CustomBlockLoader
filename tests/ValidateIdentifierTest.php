<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use InvalidStateException;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;

class ValidateIdentifierTest extends TestCase {

	/** @var CustomBlockRegistry */
	private $registry;

	protected function setUp() : void {
		$this->registry = CustomBlockLoader::getBlockRegistry();
	}

	public function testFormat1() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->validateBlockIdentifier("test_blcok");
	}

	public function testFormat2() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->validateBlockIdentifier("test:");
	}

	public function testFormat3() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->validateBlockIdentifier(":test_block");
	}

	public function testFormat4() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->validateBlockIdentifier(":");
	}

	public function testFormat5() : void {
		$this->registry->validateBlockIdentifier("test:test_block");
		self::assertTrue(true);
	}

	public function testInvalidNamespace() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->validateBlockIdentifier("minecraft:test_block");
	}

	public function testInvalidName() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->validateBlockIdentifier("test:bedrock");
	}

	public function testDuplicateName() : void {
		$this->expectException(InvalidStateException::class);
		$this->registry->add(new CustomBlockData("test1:test_block", 0, CompoundTag::create()));
		$this->registry->add(new CustomBlockData("test2:test_block", 0, CompoundTag::create()));
	}

}
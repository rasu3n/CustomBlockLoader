<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use pocketmine\block\Block;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockIdentifier;
use pocketmine\data\bedrock\LegacyBlockIdToStringIdMap;
use pocketmine\data\bedrock\LegacyToStringBidirectionalIdMap;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\AssumptionFailedError;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Webmozart\PathUtil\Path;
use function array_map;
use function assert;
use function explode;
use function file_get_contents;
use function strcasecmp;
use function usort;
use const pocketmine\RESOURCE_PATH;

class CustomBlockLoader extends PluginBase {

	private static ?CustomBlockRegistry $registry = null;
	/** @var list<CompoundTag>|null */
	private static ?array $defaultBedrockKnownStates = null;
	/** @var list<R12ToCurrentBlockMapEntry>|null */
	private static ?array $defaultLegacyStateMap = null;

	public static function getBlockRegistry() : CustomBlockRegistry {
		return self::$registry ??= new CustomBlockRegistry(array_map(fn(CompoundTag $state) => $state->getString("name"), self::loadAndGetDefaultBedrockKnownStates())); //TODO: FIXME
	}

	public function onLoad() : void {
		$asyncPool = $this->getServer()->getAsyncPool();
		$asyncPool->addWorkerStartHook(function(int $worker) use ($asyncPool) : void {
			$asyncPool->submitTaskToWorker(new ReloadCustomBlocksAsyncTask(self::getBlockRegistry()->getBlocks()), $worker);
		});
		self::reloadCustomBlocks(self::getBlockRegistry()->getBlocks());
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
			$registry = self::getBlockRegistry();
			if ($registry->hasDirtyFlag()) {
				$registry->clearDirtyFlag();
				$asyncPool = $this->getServer()->getAsyncPool();
				foreach ($asyncPool->getRunningWorkers() as $worker) {
					$asyncPool->submitTaskToWorker(new ReloadCustomBlocksAsyncTask($registry->getBlocks()), $worker);
				}
				self::reloadCustomBlocks($registry->getBlocks());
			}
		}), 1);
	}

	protected function onEnable() : void {
		$this->getServer()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $ev) : void {
			foreach ($ev->getPackets() as $packet) {
				if ($packet instanceof ResourcePackStackPacket || $packet instanceof StartGamePacket) {
					$packet->experiments = new Experiments(["data_driven_items" => true], true);
					if ($packet instanceof StartGamePacket) {
						$packet->blockPalette += array_map(fn(CustomBlockData $data) => $data->toBlockPaletteEntry(), self::getBlockRegistry()->getBlocks(), []/** ReAssign key */);
					}
				}
			}
		}, EventPriority::LOW, $this);
		// $this->debug();
	}

	private function debug() : void {
		BlockFactory::getInstance()->register(new Block(new BlockIdentifier(1000, 0), "customblockloader:test_block", BlockBreakInfo::instant()));
		$this->getServer()->getPluginManager()->registerEvent(PlayerChatEvent::class, function(PlayerChatEvent $ev) : void {
			$player = $ev->getPlayer();
			switch ($ev->getMessage()) {
				case ".set":
					$location = $player->getLocation();
					$location->getWorld()->setBlock($location, BlockFactory::getInstance()->get(1000, 0));
					$ev->cancel();
					break;
				case ".register":
					self::getBlockRegistry()->add(new CustomBlockData("customblockloader:test_block", 1000, CompoundTag::create()
						->setInt("molangVersion", 1)
						->setTag("components", CompoundTag::create()
							->setTag("minecraft:material_instances", CompoundTag::create()
								->setTag("mappings", CompoundTag::create())
								->setTag("materials", CompoundTag::create()
									->setTag("*", CompoundTag::create()
										->setByte("ambient_occlusion", 1)
										->setByte("face_dimming", 1)
										->setString("texture", "missing")
										->setString("render_method", "opaque")
									)
								)
							)
							->setTag("minecraft:unit_cube", CompoundTag::create())
							->setTag("minecraft:block_light_absorption", CompoundTag::create()
								->setInt("value", 0)
							)
						)));
					$ev->cancel();
					break;
			}
		}, EventPriority::NORMAL, $this);
	}

	/**
	 * @param array<string, CustomBlockData> $blocks
	 */
	public static function reloadCustomBlocks(array $blocks) : void {
		self::modifyItemPalette($blocks);
		try {
			self::modifyRuntimeBlockMapping($blocks);
		} catch (ReflectionException) {
			//hack for PhpStorm inspection
		}
	}

	/**
	 * @param array<string, CustomBlockData> $blocks
	 *
	 * @throws ReflectionException
	 * @see RuntimeBlockMapping::setupLegacyMappings()
	 */
	private static function modifyRuntimeBlockMapping(array $blocks) : void {
		/** @var RuntimeBlockMapping $mapping */
		$mapping = (new ReflectionClass(RuntimeBlockMapping::class))->newInstanceWithoutConstructor();
		RuntimeBlockMapping::setInstance($mapping);

		$mapping__bedrockKnownStates = new ReflectionProperty(RuntimeBlockMapping::class, "bedrockKnownStates");
		$mapping__bedrockKnownStates->setAccessible(true);
		$mapping__registerMapping = new ReflectionMethod(RuntimeBlockMapping::class, "registerMapping");
		$mapping__registerMapping->setAccessible(true);

		$bedrockKnownStates = self::loadAndGetDefaultBedrockKnownStates();
		$legacyStateMap = self::loadAndGetDefaultLegacyStateMap();

		LegacyBlockIdToStringIdMap::reset();
		/** @var LegacyBlockIdToStringIdMap $legacyIdMap */
		$legacyIdMap = LegacyBlockIdToStringIdMap::getInstance();

		$legacyIdMap__legacyToString = new ReflectionProperty(LegacyToStringBidirectionalIdMap::class, "legacyToString");
		$legacyIdMap__legacyToString->setAccessible(true);
		$legacyIdMap__stringToLegacy = new ReflectionProperty(LegacyToStringBidirectionalIdMap::class, "stringToLegacy");
		$legacyIdMap__stringToLegacy->setAccessible(true);

		$legacyToString = $legacyIdMap__legacyToString->getValue($legacyIdMap);
		$stringToLegacy = $legacyIdMap__stringToLegacy->getValue($legacyIdMap);

		foreach ($blocks as $data) {
			$legacyStateMap[] = new R12ToCurrentBlockMapEntry($data->getIdentifier(), 0, $bedrockKnownStates[] = CompoundTag::create()
				->setString("name", $data->getIdentifier())
				->setTag("states", CompoundTag::create())
				->setInt("version", 1));
			$legacyToString[$data->getLegacyId()] = $data->getIdentifier();
			$stringToLegacy[$data->getIdentifier()] = $data->getLegacyId();
		}
		self::reAssignBlockRuntimeId($bedrockKnownStates);

		$mapping__bedrockKnownStates->setValue($mapping, $bedrockKnownStates);
		$legacyIdMap__legacyToString->setValue($legacyIdMap, $legacyToString);
		$legacyIdMap__stringToLegacy->setValue($legacyIdMap, $stringToLegacy);

		/**
		 * @var int[][] $idToStatesMap string id -> int[] list of candidate state indices
		 */
		$idToStatesMap = [];
		foreach($bedrockKnownStates as $k => $state){
			$idToStatesMap[$state->getString("name")][] = $k;
		}
		foreach($legacyStateMap as $pair){
			$id = $legacyIdMap->stringToLegacy($pair->getId());
			if($id === null){
				throw new RuntimeException("No legacy ID matches " . $pair->getId());
			}
			$data = $pair->getMeta();
			if($data > 15){
				//we can't handle metadata with more than 4 bits
				continue;
			}
			$mappedState = $pair->getBlockState();
			$mappedName = $mappedState->getString("name");
			if(!isset($idToStatesMap[$mappedName])){
				throw new RuntimeException("Mapped new state does not appear in network table");
			}
			foreach($idToStatesMap[$mappedName] as $k){
				$networkState = $bedrockKnownStates[$k];
				if($mappedState->equals($networkState)){
					$mapping__registerMapping->invoke($mapping, $k, $id, $data);
					continue 2;
				}
			}
			throw new RuntimeException("Mapped new state does not appear in network table");
		}
	}

	/**
	 * @return list<CompoundTag>
	 */
	private static function loadAndGetDefaultBedrockKnownStates() : array {
		if (self::$defaultBedrockKnownStates !== null) {
			return self::$defaultBedrockKnownStates;
		}

		$canonicalBlockStatesFile = file_get_contents($path = Path::join(RESOURCE_PATH, "vanilla", "canonical_block_states.nbt"));
		if ($canonicalBlockStatesFile === false) {
			throw new AssumptionFailedError("Missing required resource file($path)");
		}
		$stream = PacketSerializer::decoder($canonicalBlockStatesFile, 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
		$bedrockKnownStates = [];
		while (!$stream->feof()) {
			$bedrockKnownStates[] = $stream->getNbtCompoundRoot();
		}

		return self::$defaultBedrockKnownStates = $bedrockKnownStates;
	}

	/**
	 * @return list<R12ToCurrentBlockMapEntry>
	 */
	private static function loadAndGetDefaultLegacyStateMap() : array {
		if (self::$defaultLegacyStateMap !== null) {
			return self::$defaultLegacyStateMap;
		}

		/** @var R12ToCurrentBlockMapEntry[] $legacyStateMap */
		$legacyStateMap = [];
		$contents = file_get_contents(Path::join(RESOURCE_PATH, "vanilla", "r12_to_current_block_map.bin"));
		assert($contents !== false);
		$legacyStateMapReader = PacketSerializer::decoder($contents, 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
		$nbtReader = new NetworkNbtSerializer();
		while (!$legacyStateMapReader->feof()) {
			$id = $legacyStateMapReader->getString();
			$meta = $legacyStateMapReader->getLShort();

			$offset = $legacyStateMapReader->getOffset();
			$state = $nbtReader->read($legacyStateMapReader->getBuffer(), $offset)->mustGetCompoundTag();
			$legacyStateMapReader->setOffset($offset);
			$legacyStateMap[] = new R12ToCurrentBlockMapEntry($id, $meta, $state);
		}

		return self::$defaultLegacyStateMap = $legacyStateMap;
	}

	/**
	 * @param list<CompoundTag> $bedrockKnownStates
	 */
	private static function reAssignBlockRuntimeId(array &$bedrockKnownStates) : void {
		usort($bedrockKnownStates, function(CompoundTag $leftState, CompoundTag $rightState) : int {
			$parseName = fn(string $name) => explode(":", $name)[1] ?? throw new AssumptionFailedError("Failed parse name");
			$leftName = $parseName($leftState->getString("name"));
			$rightName = $parseName($rightState->getString("name"));
			return strcasecmp($leftName, $rightName);
		});
	}

	/**
	 * @param array<string, CustomBlockData> $blocks
	 */
	private static function modifyItemPalette(array $blocks) : void {
		GlobalItemTypeDictionary::reset();
		$dictionary = GlobalItemTypeDictionary::getInstance()->getDictionary();
		$itemTypeDictionary__itemTypes = new ReflectionProperty(ItemTypeDictionary::class, "itemTypes");
		$itemTypeDictionary__itemTypes->setAccessible(true);
		$itemTypeDictionary__intToStringIdMap = new ReflectionProperty(ItemTypeDictionary::class, "intToStringIdMap");
		$itemTypeDictionary__intToStringIdMap->setAccessible(true);
		$itemTypeDictionary__stringToIntMap = new ReflectionProperty(ItemTypeDictionary::class, "stringToIntMap");
		$itemTypeDictionary__stringToIntMap->setAccessible(true);

		/** @var list<ItemTypeEntry> $itemTypes */
		$itemTypes = $itemTypeDictionary__itemTypes->getValue($dictionary);
		/** @var array<int, string> $intToStringIdMap */
		$intToStringIdMap = $itemTypeDictionary__intToStringIdMap->getValue($dictionary);
		/** @var array<string, int> $stringToIntMap */
		$stringToIntMap = $itemTypeDictionary__stringToIntMap->getValue($dictionary);

		$itemTypeDictionary__itemTypes->setValue($dictionary, $itemTypes);
		$itemTypeDictionary__intToStringIdMap->setValue($dictionary, $intToStringIdMap);
		$itemTypeDictionary__stringToIntMap->setValue($dictionary, $stringToIntMap);

		ItemTranslator::reset();
		$itemTranslator = ItemTranslator::getInstance();
		$itemTranslator__simpleCoreToNetMapping = new ReflectionProperty(ItemTranslator::class, "simpleCoreToNetMapping");
		$itemTranslator__simpleCoreToNetMapping->setAccessible(true);
		$itemTranslator__simpleNetToCoreMapping = new ReflectionProperty(ItemTranslator::class, "simpleNetToCoreMapping");
		$itemTranslator__simpleNetToCoreMapping->setAccessible(true);

		/** @var array<int, int> $simpleCoreToNetMapping */
		$simpleCoreToNetMapping = $itemTranslator__simpleCoreToNetMapping->getValue($itemTranslator);
		/** @var array<int, int> $simpleNetToCoreMapping */
		$simpleNetToCoreMapping = $itemTranslator__simpleNetToCoreMapping->getValue($itemTranslator);

		foreach ($blocks as $data) {
			$itemTypes[] = new ItemTypeEntry($data->getIdentifier(), $runtimeId = ($data->getLegacyId() > 255 ? 255 - $data->getLegacyId() : $data->getLegacyId()), false);
			$intToStringIdMap[$runtimeId] = $data->getIdentifier();
			$stringToIntMap[$data->getIdentifier()] = $runtimeId;
			$simpleCoreToNetMapping[$runtimeId] = $runtimeId;
			$simpleNetToCoreMapping[$runtimeId] = $runtimeId;
		}

		$itemTranslator__simpleCoreToNetMapping->setValue($itemTranslator, $simpleCoreToNetMapping);
		$itemTranslator__simpleNetToCoreMapping->setValue($itemTranslator, $simpleNetToCoreMapping);
	}

}
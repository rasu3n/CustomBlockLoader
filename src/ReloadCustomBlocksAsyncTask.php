<?php

declare(strict_types=1);

namespace Rush2929\CustomBlockLoader;

use pocketmine\scheduler\AsyncTask;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * @internal
 */
final class ReloadCustomBlocksAsyncTask extends AsyncTask {

	private string $blocks;

	/**
	 * @param array<string, CustomBlockData> $blocks
	 */
	public function __construct(array $blocks) {
		$this->blocks = igbinary_serialize($blocks);
	}

	public function onRun() : void {
		CustomBlockLoader::reloadCustomBlocks(igbinary_unserialize($this->blocks));
	}

}
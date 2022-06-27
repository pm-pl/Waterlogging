<?php

namespace platz1de\WaterLogging;

use pocketmine\block\Block;
use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water as PMWater;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\math\Vector3;

class Water extends PMWater
{
	/**
	 * This is a really dumb hack to avoid having to rewrite all the liquid spreading logic
	 * Needed as getSmallestFlowDecay() is private...
	 */
	private bool $sourceHack = false;

	public function onScheduledUpdate(): void
	{
		if ($this->falling || $this->decay > 0) {
			$adjacent = 0;
			$decay = -1;
			$this->getSmallestDecay($this->position->add(0, 0, -1), $decay, $adjacent);
			$this->getSmallestDecay($this->position->add(0, 0, 1), $decay, $adjacent);
			$this->getSmallestDecay($this->position->add(-1, 0, 0), $decay, $adjacent);
			$this->getSmallestDecay($this->position->add(1, 0, 0), $decay, $adjacent);
			if ($decay !== -1) {
				$decay += $this->getFlowDecayPerBlock();
				if ($decay > self::MAX_DECAY) {
					$decay = -1;
				}
			}
			$falling = false;

			if ($this->getEffectiveFlowDecay($this->position->getWorld()->getBlock($this->position->add(0, 1, 0))) >= 0) {
				$falling = true;
				$decay = 0;
			}

			$minAdjacentSources = $this->getMinAdjacentSourcesToFormSource();
			if ($minAdjacentSources !== null && $adjacent >= $minAdjacentSources) {
				$bottomBlock = $this->position->getWorld()->getBlockAt($this->position->x, $this->position->y - 1, $this->position->z);
				if ($bottomBlock->isSolid() || ($bottomBlock instanceof Liquid && $bottomBlock->isSameType($this) && $bottomBlock->isSource())) {
					$decay = 0;
					$falling = false;
				}
			}
			if ($decay !== $this->decay || $falling !== $this->falling) {
				$this->decay = $decay;
				$this->falling = $falling;
				if (WaterLogging::isWaterLogged($this)) {
					if ($decay === -1) {
						WaterLogging::removeWaterLogging($this);
						return;
					}
					WaterLogging::addWaterLogging($this, $decay, $falling);
				} else {
					if (!$falling && $decay === -1) {
						$this->getPosition()->getWorld()->setBlock($this->position, VanillaBlocks::AIR());
						return;
					}
					$this->position->getWorld()->setBlock($this->position, $this);
				}
			}
			$this->sourceHack = true;
		}
		parent::onScheduledUpdate();
	}

	protected function flowIntoBlock(Block $block, int $newFlowDecay, bool $falling): void
	{
		$this->sourceHack = false;
		if (!$this->canFlowInto($block)) {
			return;
		}
		if (WaterLoggableBlocks::isFlowingWaterLoggable($block)) {
			if (WaterLogging::isWaterLogged($block)) {
				return;
			}
			$new = clone $this;
			$new->falling = $falling;
			$new->decay = $falling ? 0 : $newFlowDecay;

			$ev = new BlockSpreadEvent($block, $this, $new);
			$ev->call();
			if (!$ev->isCancelled()) {
				WaterLogging::addWaterLogging($block, $newFlowDecay, $falling);
			}
			return;
		}
		parent::flowIntoBlock($block, $newFlowDecay, $falling);
	}

	public function isSource(): bool
	{
		return $this->sourceHack || parent::isSource();
	}

	/**
	 * @param Vector3 $pos
	 * @param int     $decay
	 * @param int     $sources
	 */
	private function getSmallestDecay(Vector3 $pos, int &$decay, int &$sources): void
	{
		$block = $this->position->getWorld()->getBlockAt($pos->x, $pos->y, $pos->z);
		if ($block instanceof Liquid && $block->isSameType($this)) {
			$blockDecay = $block->decay;

			if ($block->isSource()) {
				++$sources;
			} elseif ($block->falling) {
				$blockDecay = 0;
			}
		} else {
			$data = WaterLogging::getWaterDataAt($this->position->getWorld(), $pos);
			if ($data === false) {
				return;
			}
			$blockDecay = $data & 0x07;
			if ($data === 0) {
				++$sources;
			}
		}

		if ($blockDecay < $decay || $decay < 0) {
			$decay = $blockDecay;
		}
	}

	protected function getEffectiveFlowDecay(Block $block): int
	{
		if (WaterLogging::isWaterLogged($block)) {
			return WaterLogging::getWaterDecayAt($block->getPosition()->getWorld(), $block->getPosition());
		}
		return parent::getEffectiveFlowDecay($block);
	}
}
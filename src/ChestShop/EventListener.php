<?php

namespace ChestShop;

use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\block\Block;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\tile\Chest as TileChest;
use pocketmine\block\Chest as BlockChest;

class EventListener implements Listener
{
    private $plugin;
    private $databaseManager;

    public function __construct(ChestShop $plugin, DatabaseManager $dbm)
    {
        $this->plugin = $plugin;
        $this->databaseManager = $dbm;
    }

    public function onPlayerInteract(PlayerInteractEvent $event)
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();

        if ($block->getID() === Block::SIGN_POST || $block->getID() === Block::WALL_SIGN) {
            $condition = [
                "signX" => $block->getX(),
                "signY" => $block->getY(),
                "signZ" => $block->getZ()
            ];
            if (($shopInfo = $this->databaseManager->selectByCondition($condition)) === false) return;
            if ($shopInfo['shopOwner'] === $player->getName()) {
                $player->sendMessage("Cannot purchase from your own shop!");
                return;
            }
            $buyerMoney = $this->plugin->getServer()->getPluginManager()->getPlugin("PocketMoney")->getMoney($player->getName());
            if ($buyerMoney instanceof SimpleError) {
                $player->sendMessage("Couldn't get your money data!");
                return;
            }
            if ($buyerMoney < $shopInfo['price']) {
                $player->sendMessage("Your money is not enough!");
                return;
            }
            $chest = $player->getLevel()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
            $itemNum = 0;
            $pID = $shopInfo['productID'];
            $pMeta = $shopInfo['productMeta'];
            for ($i = 0; $i < BlockChest::SLOTS; $i++) {
                $item = $chest->getItem($i);
                if ($item->getID() === $pID and $item->getMetadata() === $pMeta) $itemNum += $item->getCount();
            }
            if ($itemNum < $shopInfo['saleNum']) {
                $player->sendMessage("This shop is out of stock!");
                $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])->sendMessage("Your ChestShop is out of stock! Replenish ID:${pID}!");
                return;
            }
        }
    }

    public function onPlayerPlaceBlock(BlockPlaceEvent $event)
    {
        $item = $event->getItem();
        $this->plugin->getLogger()->info($item->getID());
        if ($item->getID() === Block::SIGN_POST || $item->getID() === Block::WALL_SIGN) {
        }
        return true;
    }

    public function onSignChange(SignChangeEvent $event)
    {
        $shopOwner = $event->getPlayer()->getName();
        $saleNum = $event->getLine(1);
        $price = $event->getLine(2);
        $productData = explode(":", $event->getLine(3));
        $pID = $this->isItem($id = array_shift($productData)) ? $id : false;
        $pMeta = ($meta = array_shift($productData)) ? $meta : 0;

        $sign = $event->getBlock();

        if ($event->getLine(0) !== "") return;
        if (!is_numeric($saleNum) or $saleNum <= 0) return;
        if (!is_numeric($price) or $price < 0) return;
        if ($pID === false) return;
        if ($chest = $this->getSideChest($sign)) return;

        $productName = isset(Block::$list[$pID]) ? Block::$list[$pID]->getName() : Item::$list[$pID]->getName();
        $event->setLine(0, $shopOwner);
        $event->setLine(1, "Amount:$saleNum");
        $event->setLine(2, "Price:$price");
        $event->setLine(3, "$productName:$pMeta");

        $this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
    }

    private function getSideChest(Position $pos)
    {
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
        if ($block->getID() === Block::CHEST) return $block;
        return false;
    }

    private function isItem($id)
    {
        if (isset(Item::$list[$id])) return true;
        if (isset(Block::$list[$id])) return true;
        return false;
    }
} 
<?php

use Bitrix\Main;
use Bitrix\Sale\Basket;
use Bitrix\Main\Context;
use Bitrix\Sale\Fuser;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sale\Discount\Gift\Manager as MyGiftManager;
use Bitrix\Sale\Compatible\DiscountCompatibility;


Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleBasketSaved',
    'onSaleBasketSaved'
);

function onSaleBasketSaved(Main\Event $event)
{
    /** @var Basket $basket */
    $basket = $event->getParameter("ENTITY");

    // Получаем список товаров в корзине
    $items = $basket->getBasketItems();

    if ($basket->getPrice() == 0) {
        // Корзина пуста, ничего не добавляем
        return;
    }

    $userId = CurrentUser::get()->getId();
    $giftManager = MyGiftManager::getInstance()->setUserId($userId);

    if (!$giftManager->existsDiscountsWithGift()) {
        // Нет правил для подарков
        return;
    }

    // Получаем менеджер подарков
    DiscountCompatibility::stopUsageCompatible();
    $giftCollections = $giftManager->getCollectionsByBasket($basket);
    DiscountCompatibility::revertUsageCompatible();

    // Определяем полный список идентификаторов подарков
    $giftProductIds = [];
    foreach ($giftCollections as $collection) {
        foreach ($collection as $gift) {
            $giftProductIds[] = $gift->getProductId();
        }
    }
    
    if (empty($giftProductIds)) {
        // Подарок уже есть в корзине
        $_SESSION['GIFT_IN_BASKET'] = true;
        return;
    }

    if (!empty($_SESSION['GIFT_IN_BASKET'])) {
        // Случай, когда из корзины удалили подарок
        $_SESSION['GIFT_IN_BASKET'] = false;
        return;
    }

    // Получаем ID первого оффера у первого продукта в списке подарков
    // todo D7
    $productId = reset($giftProductIds);
    if (\CCatalogSKU::getExistOffers($productId)) {
        $giftProductID = array_shift(\CCatalogSKU::getOffersList($productId)[$productId])['ID'];
    }

    // Определяем цену предложения
    $rsPrice = \Bitrix\Catalog\PriceTable::getList([
        'filter' => ['PRODUCT_ID' => $giftProductID]
    ]);
    $price = $rsPrice->fetch();

    // Сохраняем товар в корзину
    $basketItem = $basket->createItem('catalog', $giftProductID);
    $basketItem->setFields([
        'QUANTITY' => 1,
        'CURRENCY' => $price['CURRENCY'],
        'BASE_PRICE' => $price['PRICE'],
        'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\CatalogProvider::class,
    ]);
    $basket->save();

    // Ставим признак того, что в корзине есть подарок
    $_SESSION['GIFT_IN_BASKET'] = true;
}

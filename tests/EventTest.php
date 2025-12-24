<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\OrderStatusColor42\Tests;

use Eccube\Entity\Master\OrderStatus;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Tests\Web\Admin\AbstractAdminWebTestCase;
use Plugin\OrderStatusColor42\Event;

class EventTest extends AbstractAdminWebTestCase
{
    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderStatusRepository = $this->entityManager->getRepository(OrderStatus::class);
    }

    /**
     * イベント購読のテスト
     */
    public function testGetSubscribedEvents()
    {
        $events = Event::getSubscribedEvents();
        $this->assertArrayHasKey('@admin/Order/index.twig', $events);
        $this->assertEquals('onAdminOrderIndex', $events['@admin/Order/index.twig']);
    }

    /**
     * ステータス色の取得テスト
     */
    public function testOnAdminOrderIndex_StatusColors()
    {
        // テストデータの準備
        $connection = $this->entityManager->getConnection();
        
        // mtb_order_status_colorにテストデータを追加
        $statusId = 1; // 新規受付
        $testColor = '#FF6B6B';
        // SQLite対応のため、先に削除してから挿入
        $connection->executeStatement('DELETE FROM mtb_order_status_color WHERE id = ?', [$statusId]);
        $connection->executeStatement(
            'INSERT INTO mtb_order_status_color (id, name) VALUES (?, ?)',
            [$statusId, $testColor]
        );

        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturn([]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->callback(function ($statusColors) use ($statusId, $testColor) {
                    return isset($statusColors[$statusId]) && $statusColors[$statusId] === $testColor;
                })],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->anything()],
                [$this->equalTo('opacity'), $this->equalTo(Event::OPACITY)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);

        // テストデータを削除
        $connection->executeStatement('DELETE FROM mtb_order_status_color WHERE id = ?', [$statusId]);
    }

    /**
     * デフォルト色の設定テスト
     */
    public function testOnAdminOrderIndex_DefaultColor()
    {
        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturn([]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->callback(function ($statusColors) {
                    // 全ステータスにデフォルト色が設定されているか確認
                    $allStatuses = $this->orderStatusRepository->findAll();
                    foreach ($allStatuses as $OrderStatus) {
                        $statusId = $OrderStatus->getId();
                        if (!isset($statusColors[$statusId])) {
                            return false;
                        }
                        // デフォルト色が設定されているか確認
                        if ($statusColors[$statusId] !== Event::DEFAULT_COLOR) {
                            // mtb_order_status_colorに色が設定されている場合はスキップ
                            $connection = $this->entityManager->getConnection();
                            $result = $connection->fetchOne(
                                'SELECT name FROM mtb_order_status_color WHERE id = ?',
                                [$statusId]
                            );
                            if (!$result) {
                                return false;
                            }
                        }
                    }
                    return true;
                })],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->anything()],
                [$this->equalTo('opacity'), $this->equalTo(Event::OPACITY)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);
    }

    /**
     * 無効なカラーコードの場合、デフォルト色が設定されるテスト
     */
    public function testOnAdminOrderIndex_InvalidColorCode()
    {
        // テストデータの準備
        $connection = $this->entityManager->getConnection();
        
        // 無効なカラーコードを設定
        $statusId = 1; // 新規受付
        $invalidColor = 'INVALID_COLOR';
        // SQLite対応のため、先に削除してから挿入
        $connection->executeStatement('DELETE FROM mtb_order_status_color WHERE id = ?', [$statusId]);
        $connection->executeStatement(
            'INSERT INTO mtb_order_status_color (id, name) VALUES (?, ?)',
            [$statusId, $invalidColor]
        );

        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturn([]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->callback(function ($statusColors) use ($statusId) {
                    // 無効なカラーコードの場合、デフォルト色が設定されているか確認
                    return isset($statusColors[$statusId]) && $statusColors[$statusId] === Event::DEFAULT_COLOR;
                })],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->anything()],
                [$this->equalTo('opacity'), $this->equalTo(Event::OPACITY)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);

        // テストデータを削除
        $connection->executeStatement('DELETE FROM mtb_order_status_color WHERE id = ?', [$statusId]);
    }

    /**
     * Shipping.id → OrderStatus.id のマッピング作成テスト
     */
    public function testOnAdminOrderIndex_ShippingIdToStatusIdMap()
    {
        // テストデータの準備
        $Customer = $this->createCustomer();
        $OrderStatus = $this->orderStatusRepository->find(OrderStatus::NEW);
        $Order = $this->createOrder($Customer);
        $Order->setOrderStatus($OrderStatus);
        $this->entityManager->flush();

        // Shippingを取得
        $Shippings = $Order->getShippings();
        $this->assertNotEmpty($Shippings);
        $Shipping = $Shippings[0];
        $shippingId = $Shipping->getId();
        $statusId = $OrderStatus->getId();

        // TemplateEventのモックを作成
        $pagination = $this->createMock(\Knp\Component\Pager\Pagination\PaginationInterface::class);
        $pagination->expects($this->once())
            ->method('getItems')
            ->willReturn([$Order]);

        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturnMap([
                ['pagination', $pagination],
                ['Orders', []],
            ]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->anything()],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->callback(function ($map) use ($shippingId, $statusId) {
                    // マッピングが正しく作成されているか確認
                    return isset($map[$shippingId]) && $map[$shippingId] === $statusId;
                })],
                [$this->equalTo('opacity'), $this->equalTo(Event::OPACITY)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);
    }

    /**
     * 透明度の設定テスト
     */
    public function testOnAdminOrderIndex_Opacity()
    {
        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturn([]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->anything()],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->anything()],
                [$this->equalTo('opacity'), $this->equalTo(0.1)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);
    }

    /**
     * スニペットの追加テスト
     */
    public function testOnAdminOrderIndex_AddSnippet()
    {
        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturn([]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter');
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);
    }

    /**
     * #が付いていないカラーコードの処理テスト
     */
    public function testOnAdminOrderIndex_ColorWithoutHash()
    {
        // テストデータの準備
        $connection = $this->entityManager->getConnection();
        
        // #が付いていないカラーコードを設定
        $statusId = 1; // 新規受付
        $colorWithoutHash = 'FF6B6B';
        // SQLite対応のため、先に削除してから挿入
        $connection->executeStatement('DELETE FROM mtb_order_status_color WHERE id = ?', [$statusId]);
        $connection->executeStatement(
            'INSERT INTO mtb_order_status_color (id, name) VALUES (?, ?)',
            [$statusId, $colorWithoutHash]
        );

        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturn([]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->callback(function ($statusColors) use ($statusId) {
                    // #が付加されているか確認
                    return isset($statusColors[$statusId]) && $statusColors[$statusId] === '#FF6B6B';
                })],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->anything()],
                [$this->equalTo('opacity'), $this->equalTo(Event::OPACITY)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);

        // テストデータを削除
        $connection->executeStatement('DELETE FROM mtb_order_status_color WHERE id = ?', [$statusId]);
    }

    /**
     * paginationがない場合のOrdersパラメータからの取得テスト
     */
    public function testOnAdminOrderIndex_OrdersParameter()
    {
        // テストデータの準備
        $Customer = $this->createCustomer();
        $OrderStatus = $this->orderStatusRepository->find(OrderStatus::NEW);
        $Order = $this->createOrder($Customer);
        $Order->setOrderStatus($OrderStatus);
        $this->entityManager->flush();

        // Shippingを取得
        $Shippings = $Order->getShippings();
        $this->assertNotEmpty($Shippings);
        $Shipping = $Shippings[0];
        $shippingId = $Shipping->getId();
        $statusId = $OrderStatus->getId();

        // TemplateEventのモックを作成
        $templateEvent = $this->createMock(\Eccube\Event\TemplateEvent::class);
        $templateEvent->expects($this->atLeastOnce())
            ->method('getParameter')
            ->willReturnMap([
                ['pagination', null],
                ['Orders', [$Order]],
            ]);
        $templateEvent->expects($this->atLeastOnce())
            ->method('setParameter')
            ->withConsecutive(
                [$this->equalTo('statusColors'), $this->anything()],
                [$this->equalTo('shippingIdToStatusIdMap'), $this->callback(function ($map) use ($shippingId, $statusId) {
                    // マッピングが正しく作成されているか確認
                    return isset($map[$shippingId]) && $map[$shippingId] === $statusId;
                })],
                [$this->equalTo('opacity'), $this->equalTo(Event::OPACITY)]
            );
        $templateEvent->expects($this->once())
            ->method('addSnippet')
            ->with($this->equalTo('@OrderStatusColor42/admin/order_index_script.twig'));

        // Eventインスタンスを作成
        $event = new Event($this->orderStatusRepository, $this->entityManager);
        
        // メソッドを実行
        $event->onAdminOrderIndex($templateEvent);
    }
}


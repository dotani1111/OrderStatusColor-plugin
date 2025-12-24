<?php

namespace Plugin\OrderStatusColor42;

use Eccube\Event\TemplateEvent;
use Eccube\Repository\Master\OrderStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Event implements EventSubscriberInterface
{
    /**
     * デフォルト色（グレー）
     */
    const DEFAULT_COLOR = '#999999';

    /**
     * 背景色の透明度（0.0〜1.0）
     */
    const OPACITY = 0.1;

    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * Event constructor.
     *
     * @param OrderStatusRepository $orderStatusRepository
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(OrderStatusRepository $orderStatusRepository, EntityManagerInterface $entityManager)
    {
        $this->orderStatusRepository = $orderStatusRepository;
        $this->entityManager = $entityManager;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            '@admin/Order/index.twig' => 'onAdminOrderIndex',
        ];
    }

    /**
     * 受注一覧画面にJSを注入
     *
     * @param TemplateEvent $event
     */
    public function onAdminOrderIndex(TemplateEvent $event)
    {
        // mtb_order_status_colorテーブルからステータス色を取得
        $statusColors = [];
        
        // mtb_order_status_colorテーブルから直接データを取得
        // 注意: このテーブルでは色の値はnameカラムに格納されている
        // 注意: mtb_order_status_colorはEC-CUBE標準テーブルのため、エラーハンドリングは不要
        $connection = $this->entityManager->getConnection();
        $sql = 'SELECT id, name FROM mtb_order_status_color ORDER BY id';
        $results = $connection->fetchAllAssociative($sql);
        
        foreach ($results as $row) {
            $statusId = (int)$row['id'];
            $color = $row['name'] ?: null; // nameカラムに色の値が格納されている
            
            // 色が設定されている場合
            if ($color) {
                // HEX形式に変換（念の為、#がなければ追加）
                if (strpos($color, '#') !== 0) {
                    $color = '#' . $color;
                }
                
                // カラーコードの形式を検証（#RRGGBB または #RGB 形式）
                // 正規表現: #の後に3桁または6桁の16進数（0-9, A-F, a-f）
                if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $color)) {
                    $statusColors[$statusId] = $color;
                } else {
                    // 無効なカラーコードの場合はデフォルト色を使用
                    $statusColors[$statusId] = self::DEFAULT_COLOR;
                }
            }
        }
        
        // 全ステータスを取得して、色が設定されていないものにデフォルト色（グレー）を設定
        // 注意: OrderStatusRepositoryはEC-CUBE標準のため、エラーハンドリングは不要
        $OrderStatuses = $this->orderStatusRepository->findAll();
        foreach ($OrderStatuses as $OrderStatus) {
            $statusId = $OrderStatus->getId();
            
            // mtb_order_status_colorから取得した色がない場合、デフォルト色（グレー）を使用
            if (!isset($statusColors[$statusId])) {
                $statusColors[$statusId] = self::DEFAULT_COLOR;
            }
        }
        
        // Shipping.id → OrderStatus.id のマッピングを作成
        // テンプレートイベントから受注データを取得
        $shippingIdToStatusIdMap = [];
        
        try {
            $Orders = $event->getParameter('pagination') ? $event->getParameter('pagination')->getItems() : [];
            
            // paginationがない場合は、直接Ordersパラメータを確認
            if (empty($Orders)) {
                $Orders = $event->getParameter('Orders') ?: [];
            }
            
            // 各OrderからShippingsを取得し、Shipping.id → OrderStatus.id のマッピングを作成
            foreach ($Orders as $Order) {
                try {
                    if (method_exists($Order, 'getOrderStatus') && method_exists($Order, 'getShippings')) {
                        $OrderStatus = $Order->getOrderStatus();
                        if ($OrderStatus && method_exists($OrderStatus, 'getId')) {
                            $statusId = $OrderStatus->getId();
                            
                            // OrderからShippingsを取得
                            $Shippings = $Order->getShippings();
                            if ($Shippings) {
                                foreach ($Shippings as $Shipping) {
                                    if (method_exists($Shipping, 'getId')) {
                                        $shippingId = $Shipping->getId();
                                        $shippingIdToStatusIdMap[$shippingId] = $statusId;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // 個別のOrder処理でエラーが発生した場合（ログに記録して続行）
                    if (function_exists('log_error')) {
                        $orderId = method_exists($Order, 'getId') ? $Order->getId() : 'unknown';
                        \log_error('OrderStatusColor42: Orderの処理中にエラーが発生しました', ['order_id' => $orderId, 'error' => $e->getMessage()]);
                    }
                    continue;
                }
            }
        } catch (\Exception $e) {
            // Orderの取得に失敗した場合
            if (function_exists('log_error')) {
                \log_error('OrderStatusColor42: Orderの取得に失敗しました', [$e->getMessage()]);
            }
        }
        
        // テンプレートにパラメータを追加
        $event->setParameter('statusColors', $statusColors);
        $event->setParameter('shippingIdToStatusIdMap', $shippingIdToStatusIdMap);
        $event->setParameter('opacity', self::OPACITY);
        
        // Twigテンプレートをスニペットとして追加
        $event->addSnippet('@OrderStatusColor42/admin/order_index_script.twig');
    }
}

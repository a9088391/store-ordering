<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Support\Collection;
use JsonSerializable;

final readonly class OrderDetailData implements JsonSerializable
{
    // 將已一致讀取的品項快照、狀態歷程及庫存異動組成訂單詳情。
    private function __construct(
        private OrderData $summary,
        private array $items,
        private array $history,
        private array $stockLedger,
        private ?OrderPaymentData $payment,
        private array $anomalies,
    ) {
    }

    // 各種資料列只映射契約所需的欄位，並保留呼叫端決定的順序。
    public static function fromRows(
        object $order,
        Collection $items,
        Collection $history,
        Collection $ledger,
        ?OrderPaymentData $payment,
        array $anomalies,
    ): self {
        return new self(
            OrderData::fromRow($order),
            // 品項是開單快照，保留當時的商品名稱及價格。
            $items->map(static fn (object $item): array => [
                'productId' => (int) $item->product_id,
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (int) $item->unit_price,
                'subtotal' => (int) $item->subtotal,
            ])->all(),
            // 狀態歷程共用訂單時間格式，讓介面直接依原有順序呈現。
            $history->map(static fn (object $event): array => [
                'status' => $event->status,
                'occurredAt' => OrderData::isoDate($event->occurred_at),
            ])->all(),
            // 各種庫存異動使用正數數量，由類別欄位解讀其意義。
            $ledger->map(static fn (object $event): array => [
                'productId' => (int) $event->product_id,
                'quantity' => (int) $event->quantity,
                'type' => $event->type,
                'occurredAt' => OrderData::isoDate($event->occurred_at),
            ])->all(),
            $payment,
            $anomalies,
        );
    }

    // 詳情在原有摘要欄位之上擴充明細與歷程，方便介面共用資料格式。
    public function jsonSerialize(): array
    {
        return get_object_vars($this->summary) + [
            'items' => $this->items,
            'history' => $this->history,
            'stockLedger' => $this->stockLedger,
            'payment' => $this->payment,
            'anomalies' => $this->anomalies,
        ];
    }
}

<?php

namespace Igniter\Local\Listeners;

use Admin\Models\Orders_model;
use Carbon\Carbon;
use Igniter\Flame\Location\Models\AbstractLocation;
use Igniter\Flame\Traits\EventEmitter;
use Igniter\Local\Facades\Location as LocationFacade;
use Illuminate\Contracts\Events\Dispatcher;

class MaxOrderPerTimeslotReached
{
    use EventEmitter;

    protected static $ordersCache = [];

    public function subscribe(Dispatcher $dispatcher)
    {
        $dispatcher->listen('igniter.workingSchedule.timeslotValid', __CLASS__.'@timeslotValid');
    }

    public function timeslotValid($workingSchedule, $timeslot)
    {
        $locationModel = LocationFacade::current();

        if (!(bool)$locationModel->getOption('limit_orders'))
            return;

        // Skip if the working schedule is not for delivery or pickup
        if ($workingSchedule->getType() == AbstractLocation::OPENING)
            return;

        $ordersOnThisDay = $this->getOrders($timeslot);
        if ($ordersOnThisDay->isEmpty())
            return;

        $startTime = Carbon::parse($timeslot)->subMinute();
        $endTime = Carbon::parse($timeslot)->addMinutes($locationModel->getOrderTimeInterval($workingSchedule->getType()));

        $orderCount = $ordersOnThisDay->filter(function ($order) use ($startTime, $endTime) {
            $orderTime = Carbon::createFromFormat('Y-m-d H:i:s', $order->order_date->format('Y-m-d').' '.$order->order_time);

            return $orderTime->between($startTime, $endTime);
        })->count();

        if ($orderCount >= (int)$locationModel->getOption('limit_orders_count', 50))
            return FALSE;
    }

    protected function getOrders($timeslot)
    {
        $date = Carbon::parse($timeslot)->toDateString();

        if (array_has(self::$ordersCache, $date))
            return self::$ordersCache[$date];

        $result = Orders_model::where('order_date', $date)
            ->where('location_id', LocationFacade::getId())
            ->whereIn('status_id', array_merge(setting('processing_order_status', []), setting('completed_order_status', [])))
            ->select(['order_time', 'order_date'])
            ->pluck('order_time', 'order_date');

        return self::$ordersCache[$date] = $result;
    }
}

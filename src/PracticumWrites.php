<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class PracticumWrites
{
    public function __construct(private Store $store) {}

    public function once(Actor $actor,string $operation,string $key,array $body,callable $apply): array
    {
        $digest=self::digest($body);
        $scope=[$actor->tenantId,$actor->accountId,$operation,Input::requestKey($key)];
        return $this->store->transaction(function () use ($scope,$digest,$apply) {
            $pilot = $this->store->one('SELECT mode FROM competency_rollouts WHERE tenant_id=? FOR SHARE', [$scope[0]]);
            $this->store->execute('INSERT INTO practicum_receipts (tenant_id,actor_id,operation,request_key,request_digest) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE request_digest=practicum_receipts.request_digest',[...$scope,$digest]);
            $r=$this->store->one('SELECT request_digest,result FROM practicum_receipts WHERE tenant_id=? AND actor_id=? AND operation=? AND request_key=? FOR UPDATE',$scope);
            if (!hash_equals($r['request_digest'],$digest)) throw new Problem(409,'request_key_conflict');
            if ($r['result']!==null) return json_decode($r['result'],true,flags:JSON_THROW_ON_ERROR);
            if (($pilot['mode'] ?? '') !== 'enabled') {
                throw new Problem(409, 'competency_writes_paused');
            }
            $result=$apply();
            $this->store->execute('UPDATE practicum_receipts SET result=? WHERE tenant_id=? AND actor_id=? AND operation=? AND request_key=?',
                [json_encode($result,JSON_THROW_ON_ERROR),...$scope]);
            return $result;
        });
    }

    public static function digest(array $data): string
    {
        return hash('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(array $data): array
    {
        if (!array_is_list($data)) ksort($data,SORT_STRING);
        foreach ($data as &$item) if (is_array($item)) $item=self::canonical($item);
        return $data;
    }
}

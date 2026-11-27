<?php
declare(strict_types=1);

namespace DevDelivery;

final class TrainAdapter
{
    public function call(string $operation,array $payload): array
    {
        $base=getenv('DELIVERY_TRAIN_ADAPTER_URL');
        $token=getenv('DELIVERY_TRAIN_ADAPTER_TOKEN');
        if (!$base || !$token || strlen($token)<32 || preg_match('/[\r\n]/',$token)) {
            throw new Problem(503,'train_adapter_unconfigured');
        }
        $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>5,'follow_location'=>0,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$token."\r\nConnection: close",
            'content'=>json_encode($payload,JSON_THROW_ON_ERROR)]]);
        $response=@file_get_contents(rtrim($base,'/').'/'.$operation,false,$context,0,1048576);
        if ($response===false || !preg_match('/^HTTP\/\S+ (\d{3})/',$http_response_header[0] ?? '',$match)) {
            throw new Problem(503,'train_adapter_unavailable',['operation'=>$payload['operation'] ?? null]);
        }
        $data=json_decode($response,true);
        if (!is_array($data) || $match[1]!=='200') {
            throw new Problem($match[1]==='422'?409:503,'train_adapter_rejected',['reason'=>$data['error'] ?? 'invalid_response']);
        }
        return $data;
    }
}

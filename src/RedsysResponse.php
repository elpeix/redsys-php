<?php

namespace Creagia\Redsys;

use Creagia\Redsys\Exceptions\DeniedRedsysPaymentResponseException;
use Creagia\Redsys\Exceptions\ErrorRedsysResponseException;
use Creagia\Redsys\Exceptions\InvalidRedsysResponseException;
use Creagia\Redsys\ResponseCodes\ErrorCode;
use Creagia\Redsys\ResponseCodes\ResponseCode;
use Creagia\Redsys\Support\NotificationParameters;
use Creagia\Redsys\Support\Signature;

class RedsysResponse
{
    public array $merchantParametersArray;
    public string $receivedSignature;
    public mixed $originalEncodedMerchantParameters;
    public NotificationParameters $parameters;

    public function __construct(
        private RedsysClient $redsysClient
    ) {
    }

    public function setParametersFromResponse(array $data): void
    {
        if (isset($data['errorCode'])) {
            throw new ErrorRedsysResponseException(
                $data['errorCode'],
                ErrorCode::fromCode($data['errorCode']),
            );
        }

        if (
            empty($data['Ds_SignatureVersion'])
            || empty($data['Ds_MerchantParameters'])
            || empty($data['Ds_Signature'])
        ) {
            throw new InvalidRedsysResponseException('Redsys: invalid response from bank.');
        }

        $this->originalEncodedMerchantParameters = $data['Ds_MerchantParameters'];
        $this->merchantParametersArray = json_decode(urldecode(base64_decode(strtr($data['Ds_MerchantParameters'], '-_', '+/'))), true);
        $this->receivedSignature = $data['Ds_Signature'];
        $this->parameters = NotificationParameters::fromArray($this->merchantParametersArray);
    }

    public function checkResponse(): NotificationParameters
    {
        if (isset($this->merchantParametersArray['Ds_ErrorCode'])) {
            throw new ErrorRedsysResponseException(
                $this->merchantParametersArray['Ds_ErrorCode'],
                ErrorCode::fromCode($this->merchantParametersArray['Ds_ErrorCode']),
            );
        }

        $expectedSignature = Signature::calculateSignature(
            encodedParameters: $this->originalEncodedMerchantParameters,
            order: $this->parameters->order,
            secretKey: $this->redsysClient->secretKey,
        );

        if (strtr($this->receivedSignature, '-_', '+/') !== $expectedSignature) {
            throw new InvalidRedsysResponseException("Redsys: invalid response. Signatures does not match.");
        }

        $responseCode = (int) $this->parameters->responseCode;

        if (! self::isAuthorisedCode($responseCode)) {
            throw new DeniedRedsysPaymentResponseException(
                $this->parameters->responseCode,
                ResponseCode::fromCode($this->parameters->responseCode),
            );
        }

        return $this->parameters;
    }

    public static function isAuthorisedCode(int $responseCode): bool
    {
        return ! ($responseCode > 99 && $responseCode !== 400 && $responseCode !== 900);
    }
}

<?php /** @noinspection PhpConditionAlreadyCheckedInspection */

namespace NW\WebService\References\Operations\Notification;

class Validate {
    public static function int(array $data, string $key): ?int {
        $value = $data[$key] ?? null;
        if(is_bool($value) || (self::is_number($value) && $value > 0)) {
            return (int)$value;
        }
        return null;
    }

    public static function string(array $data, string $key): ?string {
        $value = $data[$key] ?? null;
        if(is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }

    public static function is_number($value): bool { //потому что дефолтная is_numeric выдает true на 3.1, а is_int выдает false на "3"
        return (is_numeric($value) && floor($value) == $value);
    }
}

class TsReturnOperation extends ReferencesOperation
{
    private const int TYPE_NEW    = 1;
    private const int TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $result = [
            'notificationEmployeeByEmail' => false, //отправлено ли сообщение сотрудникам
            'notificationClientByEmail'   => false, //отправлено ли оповещение по email
            'notificationClientBySms'     => [
                'isSent'  => false, //отправлено ли оповещение по sms
                'message' => '',
            ],
        ];

        $data = $this->getRequestData();

        $resellerId = Validate::int($data, 'resellerId');
        if (!$resellerId) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            //возможно тут нужно Exception а не return, тогда можно было бы перенести в getReseller. Оставил как есть
            return $result;
        }

        $notificationType = Validate::int($data, 'notificationType') ?? throw new \Exception('Empty notificationType', 400);

//        $reseller = $this->getReseller($resellerId); //в оригинале не используется
        $client = $this->getClient($data);
        $creator = $this->getCreator($data);
        $expert = $this->getExpert($data);
        [$differences, $differencesFrom, $differencesTo] = $this->getDifferences($data);
        $templateData = $this->getTemplateData($data, $creator, $expert, $client, $differences);

        return $this->sendNotifications($resellerId, $client, $templateData, $notificationType, $differencesTo, $result);
    }

    private function getRequestData(): array {
        $data = $this->getRequest('data');
        if(!is_array($data) || empty($data)) {
            throw new \Exception('empty data!', 400);
        }
        return $data;
    }

    private function getReseller(int $resellerId): Contractor {
        return Employee::getById($resellerId) ?? throw new \Exception('reseller not found!', 400);
    }

    private function getClient(array $data): Contractor {
        $clientId = Validate::int($data, 'clientId');
        $resellerId = Validate::int($data, 'resellerId');
        $client = Contractor::getById($clientId);
        if (is_null($client) || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('client not found!', 400);
        }

        return $client;
    }

    private function getCreator(array $data): Contractor {
        $creatorId = Validate::int($data, 'creatorId');
        return Employee::getById($creatorId) ?? throw new \Exception('creator not found!', 400);
    }

    private function getExpert(array $data): Contractor {
        $expertId = Validate::int($data, 'expertId');
        return Employee::getById($expertId) ?? throw new \Exception('expert not found!', 400);
    }

    private function getTemplateData($data, $creator, $expert, $client, $differences): array {
        $templateData = [
            'COMPLAINT_ID'       => Validate::int($data, 'complaintId'),
            'COMPLAINT_NUMBER'   => Validate::string($data, 'complaintNumber'),
            'CREATOR_ID'         => $creator->id,
            'CREATOR_NAME'       => $creator->getFullName(),
            'EXPERT_ID'          => $expert->id,
            'EXPERT_NAME'        => $expert->getFullName(),
            'CLIENT_ID'          => $client->id,
            'CLIENT_NAME'        => $client->getFullName(),
            'CONSUMPTION_ID'     => Validate::int($data, 'consumptionId'),
            'CONSUMPTION_NUMBER' => Validate::int($data, 'consumptionNumber'),
            'AGREEMENT_NUMBER'   => Validate::int($data, 'agreementNumber'),
            'DATE'               => Validate::string($data, 'date'),
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        $keysErrors = [];
        foreach ($templateData as $key => $value) {
            if (!$value) {
                $keysErrors[] = $key;
            }
        }

        if(!empty($keysErrors)) {
            $joinKeys = implode(', ', $keysErrors);
            throw new \Exception("Template Data key ($joinKeys) is empty or incorrect!", 400);
        }
    }

    private function getDifferences(array $data): array {
        $differences = is_array($data['differences'] ?? null) ? $data['differences'] : false;
        $differencesFrom = Validate::int($differences, 'from');
        $differencesTo = Validate::int($differences, 'to');
        $resellerId = Validate::int($data, 'resellerId');
        $notificationType = Validate::int($data, 'notificationType');

        if(!$differences) {
            //todo возможно нужна какая-то дополнительная обработка, если такие случаи бывают
        }

        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName($differencesFrom),
                'TO'   => Status::getName($differencesTo),
            ], $resellerId);
        } else {
            throw new \Exception('invalid notificationType', 400);
        }

        return [$differences, $differencesFrom, $differencesTo];
    }

    private function sendNotifications(int $resellerId, Contractor $client, array $templateData, int $notificationType, $differencesTo, array $result): array {
        //такое чувство, что в оригинале пропущена обработка для $notificationType === self::TYPE_NEW
        //и судя по использованию NotificationEvents::CHANGE_RETURN_STATUS в sendEmployeeNotifications(),
        //этот кусок кода должен быть после проверки на $notificationType === self::TYPE_CHANGE
        $resellerEmail = getResellerEmailFrom($resellerId);
        if ($resellerEmail) {
            $result['notificationEmployeeByEmail'] = $this->sendEmployeeNotifications($resellerId, $resellerEmail, $client->id, $templateData);
        }

        if ($notificationType === self::TYPE_CHANGE && $differencesTo) {
            if ($client->email && $resellerEmail) {
                $result['notificationClientByEmail'] = $this->sendClientNotificationByEmail($client, $resellerEmail, $resellerId, $differencesTo, $templateData);
            }
            if ($client->mobile) {
                $result = $this->sendClientNotificationBySms($client, $resellerId, $differencesTo, $templateData, $result);
            }
        }

        return $result;
    }

    private function sendEmployeeNotifications(int $resellerId, string $resellerEmail, int $clientId, array $templateData): bool {
        $employeeEmails = getEmployeeEmailsByReseller($resellerId, 'tsGoodsReturn');
        $messages = [];
        foreach ($employeeEmails as $employeeEmail) {
            $messages[] = [
                'emailFrom' => $resellerEmail,
                'emailTo'   => $employeeEmail,
                'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
            ];
        }
        if(!empty($messages)) {
            //если правильно понял, то можно передать массив сообщений и так оптимизирование, чем отправлять по одному
            return MessagesClient::sendMessages($messages, $resellerId, $clientId, NotificationEvents::CHANGE_RETURN_STATUS);
        }
        return false;
    }

    private function sendClientNotificationByEmail(Contractor $client, string $resellerEmail, int $resellerId, $differencesTo, array $templateData): bool {
        return MessagesClient::sendMessages([[ // MessageTypes::EMAIL
            'emailFrom' => $resellerEmail,
            'emailTo'   => $client->email,
            'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
            'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
        ]], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $differencesTo);
    }

    private function sendClientNotificationBySms(Contractor $client, int $resellerId, $differencesTo, array $templateData, array $result): array{
        $isSuccess = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $differencesTo, $templateData, $error);
        if ($isSuccess) {
            $result['notificationClientBySms']['isSent'] = true;
        }
        if ($error) {
            $result['notificationClientBySms']['message'] = $error;
        }
        return $result;
    }
}
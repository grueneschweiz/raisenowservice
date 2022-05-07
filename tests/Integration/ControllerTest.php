<?php
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);


namespace Integration;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RaiseNowConnector\Controller;
use RaiseNowConnector\Util\ClientFactory;
use RaiseNowConnector\Util\Config;
use ReflectionClass;

class ControllerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // remove cached tokens
        $tokenFilePath = BASE_PATH . '/storage/app/tokens/test.json.enc';
        if (file_exists($tokenFilePath)) {
            unlink($tokenFilePath);
        }

        // clear cached webling accounting configs
        $cachedFiles = glob(BASE_PATH . '/storage/app/cache/*.json');
        foreach ($cachedFiles as $file) {
            unlink($file);
        }
    }

    /** @noinspection PhpExpressionResultUnusedInspection */
    public function tearDown(): void
    {
        // clear config singleton
        $config = new ReflectionClass(Config::class);
        $instance = $config->getProperty('instance');
        $configName = $config->getProperty('configName');
        $instance->setAccessible(true);
        $configName->setAccessible(true);
        $instance->setValue(null);
        $configName->setValue('');
        $instance->setAccessible(false);
        $configName->setAccessible(false);

        parent::tearDown();
    }

    public function testInit__NoConfigExists(): void
    {
        self::post('/invalid', []);
        self::assertEquals(404, http_response_code());
    }

    private static function post(string $relativeUrl, array $data): void
    {
        $_SERVER['REQUEST_URI'] = $relativeUrl;
        $_POST = $data;

        (new Controller())->init();
    }

    public function testInit__NotAuthenticated(): void
    {
        self::post('/test/invalid', []);
        self::assertEquals(401, http_response_code());
    }

    public function testInit__InvalidPaymentData(): void
    {
        self::post(self::getUrl(), []);
        self::assertEquals(400, http_response_code());
    }

    private static function getUrl(): string
    {
        $config = include dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';

        return '/test/' . $config['test']['webhookSecret'];
    }

    public function testInit__MemberMatch(): void
    {
        $this->mockWeblingServiceTokenRequest();
        $this->mockWeblingServiceMatchUpdateGetMemberRequest();
        $this->mockWeblingObtainConfigAndAddPaymentRequest();

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(201, http_response_code());
    }

    private function mockWeblingServiceTokenRequest(): void
    {
        ClientFactory::queueMockHandler(
            new MockHandler([
                // obtain token
                self::createResponse(
                    200,
                    ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'test_token']
                ),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );
    }

    private static function createResponse(int $statusCode, mixed $data): Response
    {
        if (is_int($data)) {
            $data = (string)$data;
        }

        if (!is_string($data)) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $data = json_encode($data, JSON_THROW_ON_ERROR);
        }

        return new Response($statusCode, [], $data);
    }

    private function mockWeblingServiceMatchUpdateGetMemberRequest(): void
    {
        ClientFactory::queueMockHandler(
            new MockHandler([
                // match member
                self::createResponse(
                    200,
                    ["status" => "match", "matches" => [self::getMemberData()]]
                ),

                // update member
                self::createResponse(200, 10),

                // get member
                self::createResponse(200, self::getMemberData()),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );
    }

    private static function getMemberData(): array
    {
        return [
            "email1" => "maria.muster@example.com",
            "firstName" => "Maria",
            "lastName" => "Muster",
            "gender" => "f",
            "recordCategory" => "private",
            "emailStatus" => "active",
            "language" => "d",
            "address1" => "Musterweg 77",
            "zip" => "88888",
            "city" => "Entenhausen",
            "newsletterCountryD" => "yes",
            "newsletterCountryF" => "no",
            "newsletterCantonD" => "yes",
            "newsletterCantonF" => null,
            "newsletterMunicipality" => "yes",
            "newsletterOther" => null,
            "pressReleaseCountryD" => "yes",
            "pressReleaseCountryF" => "no",
            "pressReleaseCantonD" => null,
            "pressReleaseCantonF" => null,
            "pressReleaseMunicipality" => "yes",
            "memberStatusCountry" => "member",
            "memberStatusCanton" => "member",
            "memberStatusRegion" => "member",
            "memberStatusMunicipality" => "member",
            "membershipStart" => null,
            "interests" => ["climate", "natureProtection"],
            "donorCountry" => "donor",
            "donorCanton" => "sponsor",
            "donorRegion" => null,
            "donorMunicipality" => null,
            "notesCountry" => "",
            "notesCanton" => "",
            "notesMunicipality" => null,
            "recordStatus" => "active",
            "id" => 10,
            "groups" => [111],
            "firstLevelGroupNames" => "BE"
        ];
    }

    private function mockWeblingObtainConfigAndAddPaymentRequest(): void
    {
        ClientFactory::queueMockHandler(
            new MockHandler([
                // get period id
                self::createResponse(200, ['objects' => [1001]]),

                // debtor does not exist
                self::createResponse(200, ['objects' => []]),

                // get donation account id
                self::createResponse(200, ['objects' => [2011]]),

                // get debtor account id
                self::createResponse(200, ['objects' => [2021]]),

                // get bank account id
                self::createResponse(200, ['objects' => [2031]]),

                // create debtor
                self::createResponse(200, 999999),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );
    }

    private static function getWebhookData(): array
    {
        return [
            "timestamp" => "1651132402",
            "name" => "rnw.system.event.transaction.status_change.final_success",
            "uuid" => "c1626a47f28d8b4",
            "merchant_config_api_key" => "grnes-0d63",
            "data" => [
                "amount" => "500",
                "epp_transaction_id" => "c14qy165537t523",
                "created" => "2022-04-28 09:52:57",
                "merchant_config" => "grnes-0d63",
                "merchant" => "grnes-699d",
                "currency" => "chf",
                "payment_method" => "TWI",
                "payment_provider" => "datatrans",
                "status" => "final_success",
                "further_rnw_interaction" => "enabled",
                "user_ip" => "85.195.236.151",
                "user_agent" => "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.133 Safari/537.36",
                "http_referer" => "https://gruene.ch/",
                "mobile_mode" => "false",
                "error_url" => "https://gruene.ch/spenden",
                "success_url" => "https://gruene.ch/spenden",
                "cancel_url" => "https://gruene.ch/spenden",
                "test_mode" => "false",
                "language" => "de",
                "return_parameters" => "false",
                "expm" => "00",
                "datatrans_merchant_id" => "3000015779",
                "internal_return_executions" => "1",
                "datatrans_upptransaction_id" => "220428095258244527",
                "datatrans_status" => "success",
                "datatrans_upp_msg_type" => "web",
                "datatrans_refno" => "c14qy165537t523",
                "datatrans_amount" => "500",
                "datatrans_currency" => "CHF",
                "datatrans_pmethod" => "TWI",
                "datatrans_reqtype" => "CAA",
                "datatrans_alias_cc" => "null",
                "datatrans_masked_cc" => "null",
                "bankiban" => "null",
                "bankbic" => "null",
                "expy" => "null",
                "datatrans_sign2" => "43f2b4d68a07450068bcce941556fe62",
                "datatrans_authorization_code" => "319834960",
                "datatrans_response_message" => "TWINT trx successful.",
                "datatrans_response_code" => "01",
                "datatrans_acq_authorization_code" => "85d6dda4-91a8-4d18-8546-a5cf3734d52b",
                "stored_rnw_product_name" => "lema",
                "stored_rnw_product_version" => "1.25.0",
                "stored_rnw_source_url" => "https://gruene.ch/spenden",
                "stored_campaign_id" => "",
                "stored_campaign_subid" => "",
                "stored_rnw_purpose_text" => "",
                "stored_rnw_hide_address_fields" => "false",
                "stored_translated_recurring_interval" => "",
                "stored_customer_salutation" => "ms",
                "stored_customer_firstname" => "Maria",
                "stored_customer_lastname" => "Muster",
                "stored_customer_email" => "maria.muster@example.com",
                "stored_customer_email_permission" => "true",
                "stored_customer_message" => "We love you, liebe GRÜNE",
                "stored_customer_donation_receipt" => "true",
                "stored_customer_company" => "Muster AG",
                "stored_customer_street" => "Musterweg",
                "stored_customer_street_number" => "77",
                "stored_customer_street2" => "",
                "stored_customer_pobox" => "Postfach 123",
                "stored_customer_zip_code" => "8888",
                "stored_customer_city" => "Entenhausen",
                "stored_customer_country" => "CH",
                "stored_rnw_widget_uuid" => "grnes-0d63",
                "stored_rnw_widget_instance_id" => "grnes-0d63-default",
                "stored_customer_birthdate" => ""
            ],
        ];
    }

    public function testInit__MemberAmbiguous(): void
    {
        $this->mockWeblingServiceTokenRequest();
        ClientFactory::queueMockHandler(
            new MockHandler([
                // match member
                self::createResponse(
                    200,
                    ["status" => "ambiguous", "matches" => [self::getMemberData()]]
                ),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(200, http_response_code());
        // todo: assert Email notification
    }
    
    public function testInit__MemberMultiple(): void
    {
        $this->mockWeblingServiceTokenRequest();
        $main = self::getMemberData();
        $duplicate = self::getMemberData();
        $duplicate['id'] = 11;
        $duplicate['memberStatusCountry'] = null;
        $duplicate['memberStatusCanton'] = null;
        $duplicate['memberStatusRegion'] = null;
        $duplicate['memberStatusMunicipality'] = null;

        ClientFactory::queueMockHandler(
            new MockHandler([
                // match member
                self::createResponse(
                    200,
                    ["status" => "multiple", "matches" => [$duplicate, $main]]
                ),

                // main member
                self::createResponse(200, $main),

                // update member
                self::createResponse(200, 10),

                // get member
                self::createResponse(200, self::getMemberData()),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );
        $this->mockWeblingObtainConfigAndAddPaymentRequest();

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(201, http_response_code());
    }

    public function testInit__MemberNone(): void
    {
        $this->mockWeblingServiceTokenRequest();

        $memberId = 99;
        $member = self::getMemberData();
        $member['id'] = $memberId;

        ClientFactory::queueMockHandler(
            new MockHandler([
                // match member
                self::createResponse(
                    200,
                    ["status" => "no_match", "matches" => []]
                ),

                // create member
                self::createResponse(201, $memberId),

                // get member
                self::createResponse(200, $member),

                // update member
                self::createResponse(200, $memberId),

                // get member
                self::createResponse(200, $member),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );
        $this->mockWeblingObtainConfigAndAddPaymentRequest();

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(201, http_response_code());
    }

    public function testInit__PaymentExists(): void
    {
        $this->mockWeblingServiceTokenRequest();
        $this->mockWeblingServiceMatchUpdateGetMemberRequest();
        ClientFactory::queueMockHandler(
            new MockHandler([
                // get period id
                self::createResponse(200, ['objects' => [1001]]),

                // debtor exists
                self::createResponse(200, ['objects' => [999999]]),

                // fail on further requests
                self::createResponse(500, []),
            ])
        );

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(200, http_response_code());
    }

    public function testInit__WeblingDown(): void
    {
        $this->mockWeblingServiceTokenRequest();
        $this->mockWeblingServiceMatchUpdateGetMemberRequest();
        ClientFactory::queueMockHandler(
            new MockHandler([
                // webling temporary down
                self::createResponse(503, ''),
            ])
        );

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(503, http_response_code());
    }

    public function testInit__ConnectionException(): void
    {
        ClientFactory::queueMockHandler(
            new MockHandler([
                new ConnectException('Failed to connect.', new Request('GET', 'test'))
            ])
        );

        self::post(self::getUrl(), self::getWebhookData());
        self::assertEquals(502, http_response_code());
    }
}

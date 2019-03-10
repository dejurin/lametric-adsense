<?php

/*
ini_set('precision', 14);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

namespace Dejurin;

class LaMetric_Adsense
{
    protected $provider;
    protected $db;
    protected $symbol = '$';
    protected $currency = 'USD';

    public function __construct($config)
    {
        $this->provider = new \League\OAuth2\Client\Provider\Google($config, $currency, $symbol);
        $this->db = new \Filebase\Database([
            'dir' => __DIR__.'/db',
            'format' => \Filebase\Format\Json::class,
            'cache' => false,
            'pretty' => true,
            'safe_filename' => true,
            'read_only' => false,
        ]);
        $this->symbol = $symbol;
        $this->currency = $currency;
    }

    public function index()
    {
        $authUrl = $this->provider->getAuthorizationUrl([
            'scope' => [
                'https://www.googleapis.com/auth/adsense.readonly',
            ],
            'prompt' => 'consent',
        ]);

        $oauth2 = $this->db->get('oauth2', ['state' => $this->provider->getState()]);
        header('Location: '.$authUrl);
        exit;
    }

    public function auth()
    {
        if (!empty($_GET['error'])) {
            exit('Got error: '.htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'));
        } elseif (empty($_GET['state']) || ($_GET['state'] !== $this->db->get('oauth2')->field('state'))) {
            $this->db->get('oauth2')->delete();
            exit('Invalid state');
        } else {
            $code = htmlspecialchars($_GET['code']);
            $token = $this->provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);

            $ownerDetails = $this->provider->getResourceOwner($token);

            $data = [
                'code' => $code,
                'token' => $token,
                'refresh_token' => $token->getRefreshToken(),
            ];

            $this->db->get('oauth2user')->save($data);
            $this->_getAdsenseAccounts($token);
        }
    }

    public function accounts()
    {
        $oauth2user = $this->db->get('oauth2user');
        $oauth2user->auth = [
            htmlspecialchars($_GET['kind']) => htmlspecialchars($_GET['id']),
        ];
        $oauth2user->save();
        header('Location: '.basename(__FILE__, '.php').'?data');
    }

    public function data()
    {
        $oauth2user = $this->db->get('oauth2user');

        $auth = $oauth2user->auth;
        $token = $oauth2user->token;
        $_token = $oauth2user->token['access_token'];

        if (time() > $oauth2user->token['expires'] - 600) {
            $token = $this->provider->getAccessToken(new League\OAuth2\Client\Grant\RefreshToken(), [
                'refresh_token' => $oauth2user->refresh_token,
            ]);
            try {
                $oauth2user->token = $token;
                $oauth2user->save();
            } catch (Exception $e) {
                exit('Something went wrong: '.$e->getMessage());
            }

            $_token = $token->getToken();
        }

        $this->_getAdsense($oauth2user->auth['adsense#account'],
            [
                'startDate' => date('Y-m-d', strtotime('-8 days')),
                'endDate' => date('Y-m-d'),
                'currency' => $this->currency,
                'dimension' => 'DATE',
                'useTimezoneReporting' => true,
            ],
            [null, 'EARNINGS', 'COST_PER_CLICK', 'PAGE_VIEWS_CTR'],
            $_token,
            $this->symbol
        );
    }

    private function _getAdsense($userId, $params, $metric, $token, $prefix)
    {
        $query = http_build_query($params).implode('&metric=', $metric);
        $url = 'https://www.googleapis.com/adsense/v1.4/accounts/'.$userId.'/reports?'.str_replace(['=0', '=1'], ['=false', '=true'], $query);
        $client = new GuzzleHttp\Client();
        $res = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ]);
        if (200 === $res->getStatusCode()) {
            $arr = json_decode($res->getBody());
            $ld = round((float) $arr->rows[8][1] - (float) $arr->rows[1][1], 2, PHP_ROUND_HALF_EVEN);

            $today = $arr->rows[8];
            unset($arr->rows[8]);
            $rows = $arr->rows;

            $earnings = [];
            $click = [];
            $ctr = [];

            foreach ($rows as $value) {
                $earnings[] = (float) $value[1];
                $click[] = (float) $value[2];
                $ctr[] = (float) $value[3];
            }

            $sum_earnings = array_sum($earnings);
            $average_earnings = array_sum($earnings) / count($earnings);
            $average_click = array_sum($click) / count($click);
            $average_ctr = array_sum($ctr) / count($ctr);

            header('Content-Type: application/json');
            echo json_encode([
                'frames' => [
                    [
                        'text' => $prefix.$today[1], // Presently
                        'icon' => 'i27458',
                    ],
                    [
                        'text' => 'LAST: '.$prefix.$arr->rows[0][1], // Last week that day
                        'icon' => 'i27458',
                    ],
                    [
                        'text' => $prefix.$ld, // Compared to this afternoon last week
                        'icon' => ($ld >= 0) ? 'i120' : 'i124',
                    ],
                    [
                        'text' => '7 DAYS: '.$prefix.$sum_earnings.' ('.$prefix.round($average_earnings, 2, PHP_ROUND_HALF_EVEN).') / '.$prefix.round($average_click, 2, PHP_ROUND_HALF_EVEN).' / '.$prefix.round($average_ctr * 100, 2, PHP_ROUND_HALF_EVEN).'%', // For all 7 days without that's day
                        'icon' => 'i27458',
                    ],
                    [
                        'text' => $prefix.round($today[2], 2, PHP_ROUND_HALF_EVEN), // Click
                        'icon' => 'i27458',
                    ],
                    [
                        'text' => round(((float) $today[3]) * 100, 2, PHP_ROUND_HALF_EVEN).'%', // CTR
                        'icon' => 'i27458',
                    ],
                ],
            ]);
        }
    }

    private function _getAdsenseAccounts($token)
    {
        $url = 'https://www.googleapis.com/adsense/v1.4/accounts';
        $client = new GuzzleHttp\Client();
        $res = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ]);

        if (200 === $res->getStatusCode()) {
            $arr = json_decode($res->getBody());
            foreach ($arr->items as $value) {
                foreach ($value as $k => $v) {
                    if ('creation_time' == $k) {
                        echo '<b>',$k,'</b>: ',date('r', $v / 1000),'</br>';
                    } else {
                        echo '<b>',$k,'</b>: ',(is_bool($v)) ? ((false === $v) ? 'No' : 'Yes') : $v,'</br>';
                    }
                }
                echo '<b>Add: ','<b><a href="/lametric.php?',http_build_query(['accounts' => true, 'kind' => htmlspecialchars($value->kind), 'id' => $value->id]),'">',$value->id,'</a></b><hr>';
            }
        }
    }
}

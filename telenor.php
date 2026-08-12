<?php
use Blesta\Core\Util\Input\Fields\InputFields;
class Telenor extends Messenger
{
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadHelpers($this, ['Html']);
        Language::loadLang('telenor', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    public function getName()
    {
        return Language::_('telenor.name', true);
    }
    public function getConfigurationFields(&$vars = [])
    {
        $fields = new InputFields();

        // user_name fields
        $credentials_user_name = $fields->label(Language::_('telenor.configuration_fields.user_name', true), 'user_name');
        $fields->setField(
            $credentials_user_name->attach(
                $fields->fieldText('user_name', $vars['user_name'] ?? '', ['id' => 'user_name'])
            )
        );
        // password fields
        $credentials_pass = $fields->label(Language::_('telenor.configuration_fields.password', true), 'password');
        $fields->setField(
            $credentials_pass->attach(
	 $fields->fieldText('password', $vars['password'] ?? '', ['id' => 'password','type' => 'password'])
            )
        );

        // Sender ID
        $sender_id_label = $fields->label(Language::_('telenor.configuration_fields.sender_id', true), 'sender_id');
        $fields->setField(
            $sender_id_label->attach(
                $fields->fieldText('sender_id', $vars['sender_id'] ?? '', ['id' => 'sender_id'])
            )
        );

        return $fields;
    }

  public function encryptableFields()
    {
        return ['password'];
    }


    public function setMeta(array $vars)
    {
        $meta_fields = ['sender_id', 'user_name', 'password'];  
        $encrypted_fields = ['password'];

        $meta = [];
        foreach ($vars as $key => $value) {
            if (in_array($key, $meta_fields)) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                ];
            }
        }

        return $meta;
    }
    public function send($to_user_id, $content, $type = null)
        {
        
        // Get messenger meta data
        $meta = $this->getMessengerMeta();

        $sender_id = $meta->sender_id ?? null;
        $user_name = $meta->user_name ?? null;
        $password = $meta->password ?? null;

        if (empty($sender_id) || empty($user_name)) {
        $this->log($to_user_id, "Error: API credentials are missing.", 'output', false);
        return;
        }
       
        Loader::loadModels($this, ['Staff', 'Clients', 'Contacts']);

        // Fetch user information
        $is_client = true;
        if (($user = $this->Staff->getByUserId($to_user_id))) {
        $mobile_number = $user->number_mobile; 
        } else {
            $user = $this->Clients->getByUserId($to_user_id);
            $phone_numbers = $this->Contacts->getNumbers($user->contact_id);

            if (is_array($phone_numbers) && !empty($phone_numbers)) {
            $mobile_number = null;
            foreach ($phone_numbers as $phone) {
            if ($phone->location === "mobile" && $phone->type === "phone") {
            $mobile_number = $phone->number;
            break; // Exit loop once found
            }
            }

            if (isset($_REQUEST['sms']) && !empty($_REQUEST['sms'])) {
                $mobile_number = $_REQUEST['sms'];
            } else {
                $user->phone_number = $mobile_number;
            }

            }
        }
            if (empty($mobile_number)) {
          //  $this->log($to_user_id, "Error: Invalid or missing mobile number - " . $mobile_number, 'output', false);
            return;
        }

    // Authenticate UserID Password
        $msisdn = $meta->user_name;
        $password = $meta->password;
        $sender_id = $meta->sender_id;

   $result = $this->sendAuthSessionID($msisdn, $password);
   $result2 = $this->sendSms($result, $mobile_number,$content,$sender_id);

    /**
     * This script handles the Telenor messenger component.
     * 
     * You can get the status and response using $result2['status'] and $result2['response'].
     * 
     * @param array $result2 An associative array containing the status and response.
     * @param string $result2['status'] The status of the operation.
     * @param string $result2['response'] The response from the operation.
     */
    

    $sucess = $result2['response'] == 'Error' ? false : true;
    $this->log($to_user_id, json_encode(['phone' => $mobile_number, 'message' => $content, 'telenor' => $result2['response']], JSON_PRETTY_PRINT), 'output', $sucess);
    return true;
      
    }
    private function sendAuthSessionID($username, $password)
    {

    $url = "https://telenorcsms.com.pk:27677/corporate_sms2/api/auth.jsp?msisdn=" . urlencode($username) . "&password="
    . urlencode($password);

    // Initialize cURL session
    $ch = curl_init();

    curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => ["Content-Type: application/xml"], // Ensuring XML response format
    ]);

    // Execute request
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
    return "cURL Error: " . $error;
    }

    // Parse XML response
    $xml = simplexml_load_string($response);
    if (!$xml) {
    return "Invalid XML response.";
    }

    // Extract values
    return (string) $xml->data;

    }
    private function sendSms($session_id, $to, $text, $mask) {

// Build the request URL
        $url = "https://telenorcsms.com.pk:27677/corporate_sms2/api/sendsms.jsp?" .
               "session_id=" . urlencode($session_id) .
               "&to=" . urlencode($to) .
               "&text=" . rawurlencode($text) .
               "&mask=" . urlencode($mask);

        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => ["Content-Type: application/xml"], // Expecting XML response
        ]);
    
        // Execute request
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
    
        if ($error) {
            return "cURL Error: " . $error;
        }
    
        // Parse XML response
        $xml = simplexml_load_string($response);
        if (!$xml) {
            return "Invalid XML response.";
        }
       

        // Extract and return values
        return [
            "command" => (string) $xml->command,
            "status" => (string) $xml->data,   // Might contain success/error message
            "response" => (string) $xml->response
        ];
    }

    }

   
    

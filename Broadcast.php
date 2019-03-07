<?
define("OAUTH2_CLIENT_ID", "CLIENT_ID");
define("OAUTH2_CLIENT_SECRET", "CLIENT_SECRET");
define("OAUTH2_CLIENT_REFRESH_TOKEN", "CLIENT_REFRESH_TOKEN");

function GoogleResponseToArray($response){
    if(isset($response)){
        $arrResponse = json_decode(json_encode($response), true);
        return $arrResponse;
    }
}

AddEventHandler("iblock", "OnBeforeIBlockElementAdd", Array("Broadcast", "OnBeforeIBlockElementAddHandler"));
AddEventHandler("iblock", "OnBeforeIBlockElementUpdate", Array("Broadcast", "OnBeforeIBlockElementUpdateHandler"));
AddEventHandler("iblock", "OnBeforeIBlockElementDelete", Array("Broadcast", "OnBeforeIBlockElementDeleteHandler"));
AddEventHandler("iblock", "OnAfterIBlockElementUpdate", Array("Broadcast", "OnAfterIBlockElementUpdateHandler"));
AddEventHandler("iblock", "OnAfterIBlockElementAdd", Array("Broadcast", "OnAfterIBlockElementAddHandler"));

class Broadcast
{
    function OnBeforeIBlockElementAddHandler(&$arFields)
    {
            global $APPLICATION;
            $arrIBlock = CIBlock::GetByID($arFields["IBLOCK_ID"]);
            $arrIBlock = $arrIBlock->GetNext();
            if ($arrIBlock["CODE"] == "translations" or $arrIBlock["CODE"] == "lecture") {
                if(!isset($arFields["PREVIEW_PICTURE"])){
                    $APPLICATION->throwException("");
                    return false;
                }
                $PROP_BROADCAST_STATUS = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE"=>"BROADCAST_STATUS"));
                $arrPROP_BROADCAST_STATUS = $PROP_BROADCAST_STATUS->Fetch();

                $arrStatus = array();
                $property_enums = CIBlockPropertyEnum::GetList(Array("ID"=>"ASC", "SORT"=>"ASC"), Array("IBLOCK_ID"=>$arFields["IBLOCK_ID"], "CODE"=>"BROADCAST_STATUS"));
                while ($enum_fields = $property_enums->GetNext()) {
                    $arrStatus[$enum_fields["ID"]] = $enum_fields["VALUE"];
                }
                if($arrStatus[$arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_STATUS["ID"]][0]["VALUE"]] == "Запланирована") {
                session_start();
                $OAUTH2_CLIENT_ID = OAUTH2_CLIENT_ID;
                $OAUTH2_CLIENT_SECRET = OAUTH2_CLIENT_SECRET;
                $OAUTH2_CLIENT_REFRESH_TOKEN = OAUTH2_CLIENT_REFRESH_TOKEN;
                $client = new Google_Client();
                $client->setClientId($OAUTH2_CLIENT_ID);
                $client->setClientSecret($OAUTH2_CLIENT_SECRET);
                if ($client->fetchAccessTokenWithRefreshToken($OAUTH2_CLIENT_REFRESH_TOKEN)) {
                    if ($_SESSION["GOOGLE_TOKEN"] = $client->getAccessToken()) {

                        $youtube = new Google_Service_YouTube($client);
                        $broadcastSnippet = new Google_Service_YouTube_LiveBroadcastSnippet();
                        $broadcastSnippet->setTitle($arFields["NAME"]);

                        $PROP_YB_DATE_BROADCAST = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "LECTURE_BEGINS"));
                        $PROP_YB_DATE_BROADCAST = $PROP_YB_DATE_BROADCAST->Fetch();

                        if (isset($arFields["PROPERTY_VALUES"][$PROP_YB_DATE_BROADCAST["ID"]]["n0"]["VALUE"]) and !$arFields["PROPERTY_VALUES"][$PROP_YB_DATE_BROADCAST["ID"]]["n0"]["VALUE"] == "") {
                            $date = new DateTime($arFields["PROPERTY_VALUES"][$PROP_YB_DATE_BROADCAST["ID"]]["n0"]["VALUE"],  new DateTimeZone('Europe/Moscow'));
                            $now = new DateTime(); $now->setTimezone('Europe/Moscow');

                            if($date < $now){
                                $APPLICATION->throwException("Введите время в будущем (Europe/Moscow)");
                                return false;
                            }

                            $arrTime = date_parse_from_format('d.m.Y G:i:s', $arFields["PROPERTY_VALUES"][$PROP_YB_DATE_BROADCAST["ID"]]["n0"]["VALUE"]);
                            $broadcastSnippet->setScheduledStartTime($arrTime['year'] . '-' . $arrTime['month'] . '-' . $arrTime['day'] . 'T' . $arrTime['hour'] . ':' . $arrTime['minute'] . ':00+03');
                        } else {
                            $APPLICATION->throwException("");
                            return false;
                        }

                        $status = new Google_Service_YouTube_LiveBroadcastStatus();
                        $status->setPrivacyStatus('unlisted');

                        $broadcastInsert = new Google_Service_YouTube_LiveBroadcast();
                        $broadcastInsert->setSnippet($broadcastSnippet);
                        $broadcastInsert->setStatus($status);
                        $broadcastInsert->setKind('youtube#liveBroadcast');

                        $broadcastsResponse = $youtube->liveBroadcasts->insert('snippet,status', $broadcastInsert, array());

                        $streamSnippet = new Google_Service_YouTube_LiveStreamSnippet();
                        $streamSnippet->setTitle($arFields["NAME"]);

                        $cdn = new Google_Service_YouTube_CdnSettings();
                        $cdn->setFormat("1080p");
                        $cdn->setIngestionType('rtmp');

                        $streamInsert = new Google_Service_YouTube_LiveStream();
                        $streamInsert->setSnippet($streamSnippet);
                        $streamInsert->setCdn($cdn);
                        $streamInsert->setKind('youtube#liveStream');

                        $streamsResponse = $youtube->liveStreams->insert('snippet,cdn', $streamInsert, array());

                        $bindBroadcastResponse = $youtube->liveBroadcasts->bind($broadcastsResponse['id'], 'id,contentDetails', array('streamId' => $streamsResponse['id'],));

                        if (GoogleResponseToArray($streamsResponse["id"])) {

                            $PROP_BROADCAST_KEY_STREAM = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "KEY_THREAD_BROADCAST"));
                            $arrPROP_BROADCAST_KEY_STREAM = $PROP_BROADCAST_KEY_STREAM->Fetch();
                            $arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_KEY_STREAM["ID"]]["n0"]["VALUE"] = GoogleResponseToArray($streamsResponse["cdn"]["ingestionInfo"]["streamName"]);

                            $PROP_BROADCAST_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "BROADCAST_ID"));
                            $arrPROP_BROADCAST_ID = $PROP_BROADCAST_ID->Fetch();
                            $arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_ID["ID"]]["n0"]["VALUE"] = GoogleResponseToArray($streamsResponse["id"]);

                            $PROP_BROADCAST_URL_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "BROADCAST_URL"));
                            $arrPROP_BROADCAST_URL_ID = $PROP_BROADCAST_URL_ID->Fetch();
                            $arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_URL_ID["ID"]]["n0"]["VALUE"] = "https://www.youtube.com/embed/" . GoogleResponseToArray($bindBroadcastResponse["id"]);

                            $PROP_URL_VIDEO_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "URL_VIDEO_ID"));
                            $arrPROP_URL_VIDEO_ID = $PROP_URL_VIDEO_ID->Fetch();
                            $arFields["PROPERTY_VALUES"][$arrPROP_URL_VIDEO_ID["ID"]]["n0"]["VALUE"] = GoogleResponseToArray($bindBroadcastResponse["id"]);

                            global $USER;
                            $arLoadProductArray = array(
                                "MODIFIED_BY"    => $USER->GetID(),
                                "IBLOCK_SECTION_ID" => false,
                                "IBLOCK_ID"      => GetIBlockID("lecture", "CHAT"),
                                "NAME"           => "Публичный чат ".base64_encode($arFields["CODE"]),
                                "ACTIVE"         => "Y"
                            );

                            $newChat = new CIBlockElement;

                            $CHAT_ID = $newChat->Add($arLoadProductArray);

                            $PROP_CHAT = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE"=>"PUBLIC_CHAT"));
                            $arrPROP_CHAT = $PROP_CHAT->Fetch();
                            $arFields["PROPERTY_VALUES"][$arrPROP_CHAT["ID"]][0]["VALUE"] = $CHAT_ID;

                            $arLoadProductArray = array(
                                "MODIFIED_BY"    => $USER->GetID(),
                                "IBLOCK_SECTION_ID" => false,
                                "IBLOCK_ID"      => GetIBlockID("lecture", "CHAT"),
                                "NAME"           => "Чат с вопросами ".base64_encode($arFields["CODE"]),
                                "ACTIVE"         => "Y"
                            );

                            $newChat_question = new CIBlockElement;

                            $CHAT_ID = $newChat_question->Add($arLoadProductArray);

                            $PROP_CHAT = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE"=>"QUESTION_CHAT"));
                            $arrPROP_CHAT = $PROP_CHAT->Fetch();
                            $arFields["PROPERTY_VALUES"][$arrPROP_CHAT["ID"]][0]["VALUE"] = $CHAT_ID;

                        } else {
                            $arrGoogleStreamResponse = GoogleResponseToArray($streamsResponse);
                            $APPLICATION->throwException(print_r($arrGoogleStreamResponse));
                            return false;
                        }
                    }
                }
            }else{
                $APPLICATION->throwException('Трансляция еще не создана, смените статус на "Запланирована"');
                return false;
            }
        }
    }

    function OnBeforeIBlockElementUpdateHandler(&$arFields){
        global $APPLICATION;
        $arrIBlock = CIBlock::GetByID($arFields["IBLOCK_ID"]);
        $arrIBlock = $arrIBlock->GetNext();
        if($arrIBlock["CODE"] == "translations" or $arrIBlock["CODE"] == "lecture") {
            if(!isset($arFields["PREVIEW_PICTURE"])){
                $APPLICATION->throwException("");
                return false;
            }
            $PROP_BROADCAST_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE"=>"BROADCAST_ID"));
            $arrPROP_BROADCAST_ID = $PROP_BROADCAST_ID->Fetch();
            $arrFields = $arFields;
            $streamID = array_shift($arrFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_ID["ID"]]);

            $PROP_URL_VIDEO_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE"=>"URL_VIDEO_ID"));
            $arrPROP_URL_VIDEO_ID = $PROP_URL_VIDEO_ID->Fetch();
            $videoID = array_shift($arrFields["PROPERTY_VALUES"][$arrPROP_URL_VIDEO_ID["ID"]]);

            session_start();
            $OAUTH2_CLIENT_ID = OAUTH2_CLIENT_ID;
            $OAUTH2_CLIENT_SECRET = OAUTH2_CLIENT_SECRET;
            $OAUTH2_CLIENT_REFRESH_TOKEN = OAUTH2_CLIENT_REFRESH_TOKEN;
            $client = new Google_Client();
            $client->setClientId($OAUTH2_CLIENT_ID);
            $client->setClientSecret($OAUTH2_CLIENT_SECRET);
            if ($client->fetchAccessTokenWithRefreshToken($OAUTH2_CLIENT_REFRESH_TOKEN)) {
                if ($_SESSION["GOOGLE_TOKEN"] = $client->getAccessToken()) {
                    $youtube = new Google_Service_YouTube($client);
                    $streamsResponse = $youtube->liveStreams->listLiveStreams('status', array('id' => $streamID["VALUE"]));

                    $arrStreamStatus = GoogleResponseToArray($streamsResponse["items"][0]["status"]);

                    $broadcastsResponse = $youtube->liveBroadcasts->listLiveBroadcasts('status', array('id' => $videoID));
                    $lifeCycleStatus = GoogleResponseToArray($broadcastsResponse["items"][0]["status"]["lifeCycleStatus"]);

                    $PROP_BROADCAST_STATUS = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE"=>"BROADCAST_STATUS"));
                    $arrPROP_BROADCAST_STATUS = $PROP_BROADCAST_STATUS->Fetch();
                    $arrStatus = array();
                    $property_enums = CIBlockPropertyEnum::GetList(Array("ID"=>"ASC", "SORT"=>"ASC"), Array("IBLOCK_ID"=>$arFields["IBLOCK_ID"], "CODE"=>"BROADCAST_STATUS"));
                    while ($enum_fields = $property_enums->GetNext()) {
                        $arrStatus[$enum_fields["ID"]] = $enum_fields["VALUE"];
                    }

                    if($lifeCycleStatus == "ready" and $arrStatus[$arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_STATUS["ID"]][0]["VALUE"]] == "Запущена") {
                        if($arrStreamStatus["streamStatus"] == "active" and $arrStreamStatus["healthStatus"]["status"] != "noData"){

                            $transitionBroadcastResponse = $youtube->liveBroadcasts->transition('testing', $videoID["VALUE"], 'id,status,contentDetails');
                            $broadcastsResponse = $youtube->liveBroadcasts->listLiveBroadcasts('status', array('id' => $videoID));

                            $lifeCycleStatus = '';
                            $waitTime = 0;
                            while($lifeCycleStatus != 'testing'){
                                $broadcastsResponse = $youtube->liveBroadcasts->listLiveBroadcasts('status', array('id' => $videoID));
                                $lifeCycleStatus = GoogleResponseToArray($broadcastsResponse["items"][0]["status"]["lifeCycleStatus"]);
                                sleep(3);
                            }

                            $transitionBroadcastResponse = $youtube->liveBroadcasts->transition('live', $videoID["VALUE"], 'id,status,contentDetails');

                        }else{
                            $APPLICATION->throwException("Видеопоток еще не запущен!");
                            return false;
                        }
                    }
                    if($lifeCycleStatus == "live" and $arrStatus[$arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_STATUS["ID"]][0]["VALUE"]] == "Завершена") {
                            $transitionBroadcastResponse = $youtube->liveBroadcasts->transition('complete', $videoID["VALUE"], 'id,status,contentDetails');
                    }

                    $res = CIBlockElement::GetByID($arFields["ID"]);
                    if($arRes = $res->Fetch())
                    {
                        $res = CIBlockSection::GetByID($arRes["IBLOCK_SECTION_ID"]);
                        if($arRes = $res->Fetch())
                        {
                            $section_code =  $arRes["CODE"];
                        }
                    }

                    if($section_code != "videoarhiv" and $arrStatus[$arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_STATUS["ID"]][0]["VALUE"]] == "Завершена"){
                        $arrArchive = GetSectionID(0, "videoarhiv", array());
                        $arFields["IBLOCK_SECTION"][0] = $arrArchive[0];
                    }

                    if($arrStatus[$arFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_STATUS["ID"]][0]["VALUE"]] == "Запланирована") {
                        $listResponse = $youtube->liveBroadcasts->listLiveBroadcasts("id,snippet", array('id' => $videoID["VALUE"]));
                        if (!empty($listResponse)) {
                            $PROP_YB_DATE_BROADCAST = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "LECTURE_BEGINS"));
                            $PROP_YB_DATE_BROADCAST = $PROP_YB_DATE_BROADCAST->Fetch();

                            $db_props = CIBlockElement::GetProperty($arFields["IBLOCK_ID"], $arFields["ID"], array("sort" => "asc"), Array("CODE"=>"LECTURE_BEGINS"));
                            $ar_props = $db_props->Fetch();

                            $StartTime = array_shift($arrFields["PROPERTY_VALUES"][$PROP_YB_DATE_BROADCAST["ID"]]);

                            $old_date = new DateTime($ar_props["VALUE"],  new DateTimeZone('Europe/Moscow'));
                            $new_date = new DateTime($StartTime["VALUE"],  new DateTimeZone('Europe/Moscow'));
                            $now = new DateTime(); $now->setTimezone('Europe/Moscow');

                            $broadcastSnippet = new Google_Service_YouTube_LiveBroadcastSnippet();
                            if($new_date >= $now or $new_date == $old_date){
                                $arrTime = date_parse_from_format('d.m.Y G:i:s', $StartTime["VALUE"]);
                                $broadcastSnippet->setScheduledStartTime($arrTime['year'] . '-' . $arrTime['month'] . '-' . $arrTime['day'] . 'T' . $arrTime['hour'] . ':' . $arrTime['minute'] . ':00+03');
                                $broadCast = $listResponse["items"][0];
                                $broadcastSnippet->setTitle($arFields["NAME"]);
                                $broadCast->setSnippet($broadcastSnippet);

                                $updateResponseBroadcast = $youtube->liveBroadcasts->update("snippet", $broadCast);
                            }else{
                                $APPLICATION->throwException("Введите время в будущем (Europe/Moscow)");
                                return false;
                            }
                        }
                    }
                }
            }
        }
    }

    function OnBeforeIBlockElementDeleteHandler($ID)
    {
        $IBLOCK_ID = CIBlockElement::GetIBlockByID($ID);
        $res = CIBlock::GetByID($IBLOCK_ID);
        $ar_res = $res->GetNext();
        if($ar_res["CODE"] == "translations" or $ar_res["CODE"] == "lecture"){
            $db_props = CIBlockElement::GetProperty($IBLOCK_ID, $ID);
            while($dump_props = $db_props->Fetch()){
                $ar_props[$dump_props["CODE"]] = $dump_props;
            }

            session_start();
            $OAUTH2_CLIENT_ID = OAUTH2_CLIENT_ID;
            $OAUTH2_CLIENT_SECRET = OAUTH2_CLIENT_SECRET;
            $OAUTH2_CLIENT_REFRESH_TOKEN = OAUTH2_CLIENT_REFRESH_TOKEN;
            $client = new Google_Client();
            $client->setClientId($OAUTH2_CLIENT_ID);
            $client->setClientSecret($OAUTH2_CLIENT_SECRET);
            if($ar_props["URL_VIDEO_ID"]["VALUE"] != "") {
                if ($client->fetchAccessTokenWithRefreshToken($OAUTH2_CLIENT_REFRESH_TOKEN)) {
                    if ($_SESSION["GOOGLE_TOKEN"] = $client->getAccessToken()) {
                        $youtube = new Google_Service_YouTube($client);
                        $deleteBroadcastResponse = $youtube->liveBroadcasts->delete($ar_props["URL_VIDEO_ID"]["VALUE"]);
                    }
                }
            }
        }
    }

    function OnAfterIBlockElementUpdateHandler(&$arFields){
        $arrFields = $arFields;
        global $APPLICATION;
        $arrIBlock = CIBlock::GetByID($arFields["IBLOCK_ID"]);
        $arrIBlock = $arrIBlock->GetNext();
        if($arrIBlock["CODE"] == "translations" or $arrIBlock["CODE"] == "lecture") {
            session_start();
            $OAUTH2_CLIENT_ID = OAUTH2_CLIENT_ID;
            $OAUTH2_CLIENT_SECRET = OAUTH2_CLIENT_SECRET;
            $OAUTH2_CLIENT_REFRESH_TOKEN = OAUTH2_CLIENT_REFRESH_TOKEN;
            $client = new Google_Client();
            $client->setClientId($OAUTH2_CLIENT_ID);
            $client->setClientSecret($OAUTH2_CLIENT_SECRET);
            if ($client->fetchAccessTokenWithRefreshToken($OAUTH2_CLIENT_REFRESH_TOKEN)) {
                if ($_SESSION["GOOGLE_TOKEN"] = $client->getAccessToken()) {
                    $PROP_URL_VIDEO_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "URL_VIDEO_ID"));
                    $arrPROP_URL_VIDEO_ID = $PROP_URL_VIDEO_ID->Fetch();
                    $videoID = array_shift($arrFields["PROPERTY_VALUES"][$arrPROP_URL_VIDEO_ID["ID"]]);

                    $youtube = new Google_Service_YouTube($client);
                    $listResponse = $youtube->videos->listVideos("snippet", array('id' => $videoID["VALUE"]));

                    if (!empty($listResponse)) {

                        $PROP_BROADCAST_THUMBNAIL = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "BROADCAST_THUMBNAIL"));
                        $arrPROP_BROADCAST_THUMBNAIL = $PROP_BROADCAST_THUMBNAIL->Fetch();
                        $arrThumbnail = array_shift($arrFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_THUMBNAIL["ID"]]);

                        if ($arrThumbnail["VALUE"]["tmp_name"]) {
                            $thumbnail_path = $arrThumbnail["VALUE"]["tmp_name"];
                            $thumbnail = new CurlFile($thumbnail_path, "image/jpeg");
                        } else {
                            if ($arrFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_THUMBNAIL["ID"]]["n0"]["VALUE"]["del"] == "Y") {
                                $thumbnail = false;
                            }

                        }

                        $ch = curl_init('https://cz-oauth.alamics.ru/');
                        curl_setopt($ch, CURLOPT_USERPWD, "chznaktest:MfZxps2q");
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);

                        curl_setopt($ch, CURLOPT_POSTFIELDS,
                            [
                                'file' => $thumbnail,
                                'CLIENT_ID' => OAUTH2_CLIENT_ID,
                                'CLIENT_SECRET' => OAUTH2_CLIENT_SECRET,
                                'VIDEO_ID' => $videoID["VALUE"],
                                'ACCESS_TOKEN' => $_SESSION["GOOGLE_TOKEN"]["access_token"],
                                'NEW_THUMBNAIL' => true
                            ]);

                        $data = curl_exec($ch);

                        $PROP_BROADCAST_STATUS = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "BROADCAST_STATUS"));
                        $arrPROP_BROADCAST_STATUS = $PROP_BROADCAST_STATUS->Fetch();

                        $arrStatus = array();
                        $property_enums = CIBlockPropertyEnum::GetList(Array("ID" => "ASC", "SORT" => "ASC"), Array("IBLOCK_ID" => $arFields["IBLOCK_ID"], "CODE" => "BROADCAST_STATUS"));
                        while ($enum_fields = $property_enums->GetNext()) {
                            $arrStatus[$enum_fields["ID"]] = $enum_fields["VALUE"];
                        }

                        $video = $listResponse[0];

                        $videoSnippet = $video['snippet'];
                        $title = $videoSnippet['title'];

                        $videoSnippet['title'] = $arFields["NAME"];

                        $updateResponse = $youtube->videos->update("snippet", $video);

                        $responseTags = $updateResponse['snippet']['title'];
                    }
                }
            }
        }
    }

    function OnAfterIBlockElementAddHandler(&$arFields)
    {
        $arrFields = $arFields;
        global $APPLICATION;
        $arrIBlock = CIBlock::GetByID($arFields["IBLOCK_ID"]);
        $arrIBlock = $arrIBlock->GetNext();
        if($arrIBlock["CODE"] == "translations" or $arrIBlock["CODE"] == "lecture") {
            session_start();
            $OAUTH2_CLIENT_ID = OAUTH2_CLIENT_ID;
            $OAUTH2_CLIENT_SECRET = OAUTH2_CLIENT_SECRET;
            $OAUTH2_CLIENT_REFRESH_TOKEN = OAUTH2_CLIENT_REFRESH_TOKEN;
            $client = new Google_Client();
            $client->setClientId($OAUTH2_CLIENT_ID);
            $client->setClientSecret($OAUTH2_CLIENT_SECRET);
            if ($client->fetchAccessTokenWithRefreshToken($OAUTH2_CLIENT_REFRESH_TOKEN)) {
                if ($_SESSION["GOOGLE_TOKEN"] = $client->getAccessToken()) {
                    if($arrFields["RESULT"] != false) {
                        $PROP_URL_VIDEO_ID = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "URL_VIDEO_ID"));
                        $arrPROP_URL_VIDEO_ID = $PROP_URL_VIDEO_ID->Fetch();
                        $videoID = array_shift($arrFields["PROPERTY_VALUES"][$arrPROP_URL_VIDEO_ID["ID"]]);

                        $youtube = new Google_Service_YouTube($client);

                        $listResponse = $youtube->videos->listVideos("snippet", array('id' => $videoID));

                        if (!empty($listResponse)) {

                            $PROP_BROADCAST_THUMBNAIL = CIBlock::GetProperties($arFields["IBLOCK_ID"], Array(), Array("CODE" => "BROADCAST_THUMBNAIL"));
                            $arrPROP_BROADCAST_THUMBNAIL = $PROP_BROADCAST_THUMBNAIL->Fetch();
                            $arrThumbnail = array_shift($arrFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_THUMBNAIL["ID"]]);

                            if ($arrThumbnail["VALUE"]["tmp_name"]) {
                                $thumbnail_path = $arrThumbnail["VALUE"]["tmp_name"];
                                $thumbnail = new CurlFile($thumbnail_path, "image/jpeg");
                            } else {
                                if ($arrFields["PROPERTY_VALUES"][$arrPROP_BROADCAST_THUMBNAIL["ID"]]["n0"]["VALUE"]["del"] == "Y") {
                                    $thumbnail = false;
                                }

                            }

                            $ch = curl_init('https://cz-oauth.alamics.ru/');
                            curl_setopt($ch, CURLOPT_USERPWD, "chznaktest:MfZxps2q");
                            curl_setopt($ch, CURLOPT_HEADER, false);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);

                            curl_setopt($ch, CURLOPT_POSTFIELDS,
                                [
                                    'file' => $thumbnail,
                                    'CLIENT_ID' => OAUTH2_CLIENT_ID,
                                    'CLIENT_SECRET' => OAUTH2_CLIENT_SECRET,
                                    'VIDEO_ID' => $videoID["VALUE"],
                                    'ACCESS_TOKEN' => $_SESSION["GOOGLE_TOKEN"]["access_token"],
                                    'NEW_THUMBNAIL' => true
                                ]);

                            $data = curl_exec($ch);
                        }
                    }
                }
            }
        }
    }
}
?>
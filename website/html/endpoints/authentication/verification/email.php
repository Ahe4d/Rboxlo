<?php
    require_once($_SERVER["DOCUMENT_ROOT"] . "/../backend/includes.php");

    require_once($_SERVER["DOCUMENT_ROOT"] . "/../backend/PHPMailer/src/Exception.php");
    require_once($_SERVER["DOCUMENT_ROOT"] . "/../backend/PHPMailer/src/PHPMailer.php");
    require_once($_SERVER["DOCUMENT_ROOT"] . "/../backend/PHPMailer/src/SMTP.php");

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;

    header("Content-Type: text/plain");

    // Defaults to an error
    $success = false;
    $message = "An unexpected error occurred.";

    $error = false; // This variable is set so we don't perform additional checks if we already know that something is invalid.
                    // However, one issue with this is that we have to have nested if-else cases.
                    // It sucks, but that's life.

    if (!isset($_POST["information"]))
    {
        $message = "Nothing was sent.";
        $error = true;
    }

    if (!isset($_SESSION["user"]) && !$error)
    {
        $message = "You need to be logged in in order to verify your E-Mail address.";
        $error = true;
    }

    if (!$error)
    {
        $information = json_decode($_POST["information"], true);

        if ($information["csrf"] !== $_SESSION["csrf"])
        {
            $message = "Invalid CSRF.";
            $error = true;
        }

        // Verify recaptcha
        if (!isset($information["recaptcha"]) || empty($information["recaptcha"]) && !$error)
        {
            $message = "Please solve the reCAPTCHA.";
            $error = true;
        }

        if (!$error)
        {
            
            $url = "https://www.google.com/recaptcha/api/siteverify";
            $data = [
                "secret" => G_RECAPTCHA_PRIVATE_KEY,
                "response" => $information["recaptcha"],
                "remoteip" => get_user_ip()
            ];
            
            $options = [
                "http" => [
                    "header"  => "Content-type: application/x-www-form-urlencoded\r\n",
                    "method"  => "POST",
                    "content" => http_build_query($data)
                ]
            ];
            
            $context = stream_context_create($options);
            $result = json_decode(file_get_contents($url, false, $context), true);
            
            if (!$result["success"])
            {
                $message = "You failed to solve the reCAPTCHA challenge. Please try again.";
                $error = false;
            }
        }

        if ($information["send"] && !$error) // if it is sent, do this
        {
            // Delete existing tokens
            $statement = $sql->prepare("DELETE FROM `email_verification_tokens` WHERE `uid` = ?");
            $statement->execute([$_SESSION["user"]["id"]]);

            // Create a verification token
            $token = hash("sha256", bin2hex(random_bytes(64)));

            $statement = $sql->prepare("INSERT INTO `email_verification_tokens` (`token`, `generated`, `uid`) VALUES (?, ?, ?)");
            $statement->execute([$token, time(), $_SESSION["user"]["id"]]);

            // Get user's email
            $statement = $sql->prepare("SELECT `email` FROM `users` WHERE `id` = ?");
            $statement->execute([$_SESSION["user"]["id"]]);
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            
            // Decrypt email
            $email = _crypt($result["email"], "decrypt");

            // Find email alias
            $user_email_alias = substr($email, 0, strpos($email, "@")); // alias ("john@gmail.com" -> "john")

            // Structure the email verification URL
            $verification_url = "https://". BASE_URL ."/my/verify?token=". $token;

            // Send E-Mail
            $mail = new PHPMailer;

            $mail->isSMTP();
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->Host = "smtp.gmail.com";
            $mail->Port = 587;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            $mail->Username = EMAIL_USERNAME;
            $mail->Password = EMAIL_PASSWORD;
                
            $mail->setFrom(EMAIL_USERNAME, BASE_NAME);
            $mail->addReplyTo(EMAIL_USERNAME, BASE_NAME);

            $mail->addAddress($email, $user_email_alias);

            $mail->Subject = BASE_NAME . " Verification for ". $_SESSION["user"]["username"];

            $mail->msgHTML(
                "<h3>". BASE_NAME ."</h3><br>Hi ". $_SESSION["user"]["username"] .", please verify your E-Mail address at <a href=\"$verification_url\">$verification_url</a>.<br> Not ". $_SESSION["user"]["username"] ."? Ignore this message."
            );

            if ($mail->send())
            {
                $message = "Successfully sent E-Mail! Please check all inboxes.";
                $success = true;
            }
            else
            {
                $message = "An unexpected error occurred.";
            }
        }
        else if (!$error)
        {
            if (empty($information["token"]) || !isset($information["token"]) && !$error)
            {
                $message = "You need to specify a token for verification.";
                $error = true;
            }

            if (!ctype_alnum($information["token"]) || strlen($information["token"]) !== 64 && !$error)
            {
                $message = "Invalid token.";
                $error = true;
            }

            // funny db stuff
            if (!$error)
            {
                $statement = $GLOBALS["sql"]->prepare("SELECT `generated`, `uid` FROM `email_verification_tokens` WHERE `token` = ?");
                $statement->execute([$information["token"]]);
                $result = $statement->fetch(PDO::FETCH_ASSOC);

                if (!$result)
                {
                    $message = "That token doesn't exist.";
                    $error = true;
                }
                
                if (!$error)
                {
                    $elapsed = time() - $result["generated"];
                    if ($elapsed >= 900) // If 15 minutes have elapsed since token generation
                    {
                        $message = "That verification token has expired (15 minute timeout.) Please use a new one.";
                        $error = true;
                    }

                    // passed all checks - do this:
                    // -> delete token
                    // -> set user as verified
                    if (!$error)
                    {
                        $statement = $GLOBALS["sql"]->prepare("UPDATE `users` SET `email_verified` = 1 WHERE `id` = ?");
                        $statement->execute([$result["uid"]]);

                        $statement = $GLOBALS["sql"]->prepare("DELETE FROM `email_verification_tokens` WHERE `generated` = ?");
                        $statement->execute([$result["generated"]]);
                        $_SESSION["user"]["email_verified"] = true;

                        $message = "Successfully verified user! Redirecting you back to your dashboard...";
                        $success = true;
                    }
                }
            }
        }
    }

    exit(json_encode([
        "success" => $success,
        "message" => $message
    ]));
?>
<?php

/*
 The SMTP Transport from Swift Mailer.
 
 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.
 
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.
 
 */

//@require 'Swift/Mailer/Transport.php';
//@require 'Swift/Mailer/Transport/SmtpExtensionHandler.php';
//@require 'Swift/Mailer/Transport/IoBuffer.php';
//@require 'Swift/Mailer/Transport/SmtpBufferWrapper.php';
//@require 'Swift/Mime/Message.php';

/**
 * Sends Messages over SMTP.
 * @package Swift
 * @subpackage Transport
 * @author Chris Corbyn
 */
class Swift_Mailer_Transport_SmtpTransport
  implements Swift_Mailer_Transport, Swift_Mailer_Transport_SmtpBufferWrapper
{
  
  /**
   * An Input-Output buffer for sending/receiving SMTP commands and responses.
   * @var Swift_Mailer_Transport_IoBuffer
   * @access private
   */
  private $_buffer;
  
  /**
   * Connection buffer parameters.
   * @var array
   * @access private
   */
  private $_params = array(
    'protocol' => 'tcp',
    'host' => 'localhost',
    'port' => 25,
    'timeout' => 30,
    'blocking' => 1,
    'type' => Swift_Mailer_Transport_IoBuffer::TYPE_SOCKET
    );
  
  /**
   * Connection status.
   * @var boolean
   * @access private
   */
  private $_started = false;
  
  /**
   * The domain name to use in EHL0/HELO commands.
   * @var string
   * @access private
   */
  private $_domain = 'localhost';
  
  /**
   * Creates a new SmtpTransport using the given I/O buffer.
   * @param Swift_Mailer_Transport_IoBuffer $buf
   * @param Swift_Mailer_Transport_SmtpExtensionHandler[] $extensionHandlers
   */
  public function __construct(Swift_Mailer_Transport_IoBuffer $buf, array $extensionHandlers)
  {
    $this->_buffer = $buf;
  }
  
  /**
   * Test if an SMTP connection has been established.
   * @return boolean
   */
  public function isStarted()
  {
    return $this->_started;
  }
  
  /**
   * Start the SMTP connection.
   */
  public function start()
  {
    if (!$this->_started)
    {
      $this->_buffer->initialize($this->_params);
      $this->_assertResponseCode($this->_getFullResponse(0), array(220));
      try
      {
        $this->executeCommand(sprintf("EHLO %s\r\n", $this->_domain), array(250));
      }
      catch (Exception $e)
      {
        $this->executeCommand(sprintf("HELO %s\r\n", $this->_domain), array(250));
      }
      $this->_started = true;
    }
  }
  
  /**
   * Stop the SMTP connection.
   */
  public function stop()
  {
    if ($this->_started)
    {
      try
      {
        $this->executeCommand("QUIT\r\n", array(221));
      }
      catch (Exception $e)
      {//log this? 
      }
      $this->_buffer->terminate();
    }
    $this->_started = false;
  }
  
  /**
   * Send the given Message.
   * Recipient/sender data will be retreived from the Message API.
   * The return value is the number of recipients who were accepted for delivery.
   * @param Swift_Mime_Message $message
   * @return int
   */
  public function send(Swift_Mime_Message $message)
  {
    $sent = 0;
    
    if (!$reversePath = $this->_getReversePath($message))
    {
      throw new Exception('Cannot send message without a sender address');
    }
    
    $to = $message->getTo();
    $cc = $message->getCc();
    $bcc = $message->getBcc();
    //Remove Bcc headers initially
    if (!empty($bcc))
    {
      $message->setBcc(array());
    }
    
    //Send to all direct recipients
    if (!empty($to) || !empty($cc))
    {
      try
      {
        $sent += $this->_doMailTransaction(
          $message, $reversePath, array_merge(
            array_keys((array)$to), array_keys((array)$cc)
            )
          );
      }
      catch (Exception $e)
      {
        if (!empty($bcc)) //don't leave $message in a state it wasn't given in
        {
          $message->setBcc($bcc);
        }
        throw $e;
      }
    }
    
    //Send blind copies
    if (!empty($bcc))
    {
      foreach ((array) $bcc as $forwardPath => $name)
      {
        //Update the message for this recipient
        $message->setBcc(array($forwardPath => $name));
        try
        {
          $sent += $this->_doMailTransaction(
            $message, $reversePath, array($forwardPath)
            );
        }
        catch (Exception $e)
        {
           //don't leave $message in a state it wasn't given in
          $message->setBcc($bcc);
          throw $e;
        }
      }
    }
    
    //Restore Bcc headers
    if (!empty($bcc))
    {
      $message->setBcc($bcc);
    }
    
    return $sent;
  }
  
  /**
   * Set the name of the local domain which Swift will identify itself as.
   * This should be a fully-qualified domain name and should be truly the domain
   * you're using.  If your server doesn't have a domain name, use the IP in square
   * brackets (i.e. [127.0.0.1]).
   * @param string $domain
   */
  public function setLocalDomain($domain)
  {
    $this->_domain = $domain;
  }
  
  /**
   * Get the name of the domain Swift will identify as.
   * @return string
   */
  public function getLocalDomain()
  {
    return $this->_domain;
  }
  
  /**
   * Set the connection timeout.
   * @param int $timeout seconds
   */
  public function setTimeout($timeout)
  {
    $this->_params['timeout'] = (int) $timeout;
  }
  
  /**
   * Get the connection timeout.
   * @return int
   */
  public function getTimeout()
  {
    return $this->_params['timeout'];
  }
  
  /**
   * Reset the current mail transaction.
   */
  public function reset()
  {
    $this->executeCommand("RSET\r\n", array(250));
  }
  
  /**
   * Get the IoBuffer where read/writes are occurring.
   * @return Swift_Mailer_Transport_IoBuffer
   */
  public function getBuffer()
  {
    return $this->_buffer;
  }
  
  /**
   * Run a command against the buffer, expecting the given response codes.
   * If no response codes are given, the response will not be validated.
   * If codes are given, an exception will be thrown on an invalid response.
   * @param string $command
   * @param int[] $codes
   * @return string
   */
  public function executeCommand($command, $codes = array())
  {
    $seq = $this->_buffer->write($command);
    $response = $this->_getFullResponse($seq);
    $this->_assertResponseCode($response, $codes);
    return $response;
  }
  
  // -- Private methods
  
  /**
   * Checks if the response code matches a given number.
   * @param string $response
   * @param int $wanted
   * @throws Exception if the assertion fails
   */
  private function _assertResponseCode($response, $wanted)
  {
    list($code, $separator, $text) = sscanf($response, '%3d%[ -]%s');
    if (!empty($wanted) && !in_array($code, $wanted))
    {
      throw new Exception(
        'Expected response code ' . implode('/', $wanted) . ' but got code ' .
        '"' . $code . '", with message "' . $response . '"'
        );
    }
  }
  
  /**
   * Get the entire response of a multi-line response.
   * @param int $seq number from {@link write()}.
   * @return string
   * @access private
   */
  private function _getFullResponse($seq)
  {
    $response = '';
    do
    {
      $line = $this->_buffer->readLine($seq);
      $response .= $line;
    }
    while (null !== $line && false !== $line && ' ' != $line{3});
    return $response;
  }
  
  /**
   * Determine the best-use reverse path for this message.
   * The preferred order is: return-path, sender, from.
   * @param Swift_Mime_Message $message
   * @return string
   */
  private function _getReversePath(Swift_Mime_Message $message)
  {
    $return = $message->getReturnPath();
    $sender = $message->getSender();
    $from = $message->getFrom();
    $path = null;
    if (!empty($return))
    {
      $path = $return;
    }
    elseif (!empty($sender))
    {
      $keys = array_keys($sender);
      $path = array_shift($keys);
    }
    elseif (!empty($from))
    {
      $keys = array_keys($from);
      $path = array_shift($keys);
    }
    return $path;
  }
  
  /**
   * Send the given email to the given recipients from the given reverse path.
   * @param Swift_Mime_Message $message
   * @param string $reversePath
   * @param string[] $recipients
   * @return int
   */
  private function _doMailTransaction($message, $reversePath, array $recipients)
  {
    $sent = 0;
    
    //Provide sender address
    $this->executeCommand(sprintf("MAIL FROM: <%s>\r\n", $reversePath), array(250));
    
    foreach ($recipients as $forwardPath)
    {
      try
      {
        $this->executeCommand(
          sprintf("RCPT TO: <%s>\r\n", $forwardPath), array(250, 251, 252)
          );
        $sent++;
      }
      catch (Exception $e)
      {
      }
    }
    
    if ($sent > 0)
    {
      $this->executeCommand("DATA\r\n", array(354));
      //Stream the message straight into the buffer
      $this->_buffer->setWriteTranslations(array("\n." => "\n.."));
      $message->toByteStream($this->_buffer);
      //End data transmission
      $this->_buffer->setWriteTranslations(array());
      $this->executeCommand("\r\n.\r\n", array(250));
    }
    else
    {
      $this->reset();
    }
    
    return $sent;
  }
  
  /**
   * Destructor.
   */
  public function __destruct()
  {
    $this->stop();
  }
  
}

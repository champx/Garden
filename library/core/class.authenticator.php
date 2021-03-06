<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

/**
 * An abstract template for authenticator classes.
 *
 * @author Tim Gunter
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Garden
 * @version @@GARDEN-VERSION@@
 * @namespace Garden.Core
 */

/**
 * An abstract template for authenticator classes.
 *
 * @package Garden
 */
abstract class Gdn_Authenticator extends Gdn_Pluggable {
   
   const DATA_NONE            = 'data none';
   const DATA_FORM            = 'data form';
   const DATA_REQUEST         = 'data request';
   const DATA_COOKIE          = 'data cookie';
   
   const MODE_REPEAT          = 'already logged in';
   const MODE_GATHER          = 'gather';
   const MODE_VALIDATE        = 'validate';
   const MODE_NOAUTH          = 'no foreign identity';
   
   const AUTH_DENIED          = 0;
   const AUTH_PERMISSION      = -1;
   const AUTH_INSUFFICIENT    = -2;
   const AUTH_PARTIAL         = -3;
   const AUTH_SUCCESS         = -4;
   const AUTH_ABORTED         = -5;
   
   const REACT_RENDER         = 0;
   const REACT_EXIT           = 1;
   const REACT_REDIRECT       = 2;
   const REACT_REMOTE         = 3;
   
   const URL_REGISTER         = 'RegisterUrl';
   const URL_SIGNIN           = 'SignInUrl';
   const URL_SIGNOUT          = 'SignOutUrl';
   
   const KEY_TYPE_TOKEN       = 'token';
   const KEY_TYPE_PROVIDER    = 'provider';
   
   /**
    * Alias of the authentication scheme to use, e.g. "password" or "openid"
    *
    */
   protected $_AuthenticationSchemeAlias = NULL;
   
   /**
    * Contains authenticator configuration information, such as a preshared key or
    * discovery URL.
    *
    */
   protected $_AuthenticationProviderModel = NULL;
   protected $_AuthenticationProviderData = NULL;
   
   protected $_DataSourceType = self::DATA_FORM;
   protected $_DataSource = NULL;
   public $_DataHooks = array();

   /**
    * Returns the unique id assigned to the user in the database, 0 if the
    * username/password combination weren't found, or -1 if the user does not
    * have permission to sign in.
    */
   abstract public function Authenticate();
   abstract public function CurrentStep();
   abstract public function DeAuthenticate();
   abstract public function WakeUp();
   
   // What to do if entry/auth/* is called while the user is logged out. Should normally be REACT_RENDER
   abstract public function LoginResponse();
   
   // What to do after part 1 of a 2 part authentication process. This is used in conjunction with OAauth/OpenID type authentication schemes
   abstract public function PartialResponse();
   
   // What to do after authentication has succeeded. 
   abstract public function SuccessResponse();
   
   // What to do if the entry/auth/* page is triggered for a user that is already logged in
   abstract public function RepeatResponse();
   
   // Get one of the three Forwarding URLs (Registration, SignIn, SignOut)
   abstract public function GetURL($URLType);

   public function __construct() {
      // Figure out what the authenticator alias is
      $this->_AuthenticationSchemeAlias = $this->GetAuthenticationSchemeAlias();
      
      // Initialize gdn_pluggable
      parent::__construct();
   }
   
   public function DataSourceType() {
      return $this->_DataSourceType;
   }
   
   public function FetchData($DataSource, $DirectSupplied = array()) {
      $this->_DataSource = $DataSource;
      
      if ($DataSource == $this) {
         foreach ($this->_DataHooks as $DataTarget => $DataHook)
            $this->_DataHooks[$DataTarget]['value'] = ArrayValue($DataTarget, $DirectSupplied);
            
         return;
      }
      
      if (sizeof($this->_DataHooks)) {
         foreach ($this->_DataHooks as $DataTarget => $DataHook) {
            switch ($this->_DataSourceType) {
               case self::DATA_REQUEST:
               case self::DATA_FORM:
                  $this->_DataHooks[$DataTarget]['value'] = $this->_DataSource->GetValue($DataHook['lookup'], FALSE);
               break;
               
               case self::DATA_COOKIE:
                  $this->_DataHooks[$DataTarget]['value'] = $this->_DataSource->GetValueFrom(Gdn_Authenticator::INPUT_COOKIES, $DataHook['lookup'], FALSE);
               break;
            }
         }
      }
   }
   
   public function HookDataField($InternalFieldName, $DataFieldName, $DataFieldRequired = TRUE) {
      $this->_DataHooks[$InternalFieldName] = array('lookup' => $DataFieldName, 'required' => $DataFieldRequired);
   }

   public function GetValue($Key, $Default = FALSE) {
      if (array_key_exists($Key, $this->_DataHooks) && array_key_exists('value', $this->_DataHooks[$Key]))
         return $this->_DataHooks[$Key]['value'];
         
      return $Default;
   }
   
   protected function _CheckHookedFields() {
      foreach ($this->_DataHooks as $DataKey => $DataValue) {
         if ($DataValue['required'] == TRUE && (!array_key_exists('value', $DataValue) || $DataValue['value'] == NULL)) return Gdn_Authenticator::MODE_GATHER;
      }
      
      return Gdn_Authenticator::MODE_VALIDATE;
   }
   
   public function LoadProvider($AuthenticationProviderLookupKey) {
      
      $this->_AuthenticationProviderModel = new Gdn_AuthenticationProviderModel();
      $AuthenticatorData = $this->_AuthenticationProviderModel->GetProviderByKey($AuthenticationProviderLookupKey);
      
      if ($AuthenticatorData) {
         $this->_AuthenticationProviderData = $AuthenticatorData;
      }
      else {
         throw new Exception("Tried to load bogus authentication provider via lookup key'{$AuthenticationProviderLookupKey}'. No information stored for this key.");
      }
   }
   
   public function CreateToken($TokenType, $ProviderKey, $UserKey = NULL, $Authorized = FALSE) {
      $TokenKey = implode('.', array('token',$ProviderKey,time(),mt_rand(0,100000)));
      $TokenSecret = sha1(md5(implode('.',array($TokenKey,mt_rand(0,100000)))));
      $Timestamp = time();
      
      $InsertArray = array(
         'Token' => $TokenKey,
         'TokenSecret' => $TokenSecret,
         'TokenType' => $TokenType,
         'ProviderKey' => $ProviderKey,
         'Lifetime' => Gdn::Config('Garden.Authenticators.handshake.TokenLifetime', 60),
         'Timestamp' => date('Y-m-d H:i:s',$Timestamp),
         'Authorized' => $Authorized
      );
      
      if ($UserKey !== NULL)
         $InsertArray['ForeignUserKey'] = $UserKey;
      
      try {
         Gdn::Database()->SQL()->Insert('UserAuthenticationToken', $InsertArray);
         if ($TokenType == 'access' && !is_null($UserKey))
            $this->DeleteToken($ProviderKey, $UserKey, 'request');
      } catch(Exception $e) {
         return FALSE;
      }
         
      return $InsertArray;
   }
   
   public function LookupToken($ProviderKey, $UserKey, $TokenType = NULL) {
   
      $TokenData = Gdn::Database()->SQL()
         ->Select('uat.*')
         ->From('UserAuthenticationToken uat')
         ->Where('uat.ForeignUserKey', $UserKey)
         ->Where('uat.ProviderKey', $ProviderKey)
         ->BeginWhereGroup()
            ->Where('(uat.Timestamp + uat.Lifetime) >=', 'NOW()')
            ->OrWHere('uat.Lifetime', 0)
         ->EndWhereGroup()
         ->Get()
         ->FirstRow(DATASET_TYPE_ARRAY);
         
      if ($TokenData && (is_null($TokenType) || strtolower($TokenType) == strtolower($TokenData['TokenType'])))
         return $TokenData;
      
      return FALSE;
   }
   
   public function DeleteToken($ProviderKey, $UserKey, $TokenType) {
      Gdn::Database()->SQL()
         ->From('UserAuthenticationToken')
         ->Where('ProviderKey', $ProviderKey)
         ->Where('ForeignUserKey', $UserKey)
         ->Where('TokenType', $TokenType)
         ->Delete();
   }
   
   public function SetNonce($TokenKey, $Nonce, $Timestamp = NULL) {
      $InsertArray = array(
         'Token'     => $TokenKey,
         'Nonce'     => $Nonce,
         'Timestamp' => date('Y-m-d H:i:s',(is_null($Timestamp)) ? time() : $Timestamp)
      );
      
      try {
         $NumAffected = Gdn::Database()->SQL()->Update('UserAuthenticationNonce')
            ->Set('Nonce', $Nonce)
            ->Set('Timestamp', $InsertArray['Timestamp'])
            ->Where('Token', $InsertArray['Token'])
            ->Put();
            
         if (!$NumAffected->PDOStatement()->rowCount())
            throw new Exception();
      } catch (Exception $e) {
         Gdn::Database()->SQL()->Insert('UserAuthenticationNonce', $InsertArray);
      }
      return TRUE;
   }
   
   public function LookupNonce($TokenKey, $Nonce = NULL) {
      
      $NonceData = Gdn::Database()->SQL()->Select('uan.*')
         ->From('UserAuthenticationNonce uan')
         ->Where('uan.Token', $TokenKey)
         ->Get()
         ->FirstRow(DATASET_TYPE_ARRAY);
         
      if ($NonceData && (is_null($Nonce) || $NonceData['Nonce'] == $Nonce))
         return $NonceData['Nonce'];
      
      return FALSE;
   }
   
   public function ClearNonces($TokenKey) {
      Gdn::SQL()->Delete('UserAuthenticationNonce', array(
         'Token'  => $TokenKey
      ));
   }
   
   public function RequireLogoutTransientKey() {
      return TRUE;
   }
   
   public function GetAuthenticationSchemeAlias() {
      $StipSuffix = str_replace('Gdn_','',__CLASS__);
      $ClassName = str_replace('Gdn_','',get_class($this));
      $ClassName = substr($ClassName,-strlen($StipSuffix)) == $StipSuffix ? substr($ClassName,0,-strlen($StipSuffix)) : $ClassName;
      return strtolower($ClassName);
   }

   public function GetProviderValue($Key, $Default = FALSE) {
      if (array_key_exists($Key, $this->_AuthenticationProviderData))
         return $this->_AuthenticationProviderData[$Key];
         
      return $Default;
   }
   
   public function SetIdentity($UserID, $Persist = TRUE) {
      $AuthenticationSchemeAlias = $this->GetAuthenticationSchemeAlias();
      Gdn::Authenticator()->SetIdentity($UserID, $Persist);
      Gdn::Session()->Start();
      
      if ($UserID > 0) {
         Gdn::Session()->SetPreference('Authenticator', $AuthenticationSchemeAlias);
      } else {
         Gdn::Session()->SetPreference('Authenticator', '');
      }
   }
   
   public function GetProviderKey() {
      return $this->GetProviderValue('AuthenticationKey');
   }
   
   public function GetProviderSecret() {
      return $this->GetProviderValue('AssociationSecret');
   }
   
   public function GetProviderUrl() {
      return $this->GetProviderValue('URL');
   }

}

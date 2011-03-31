<?php if (!defined('APPLICATION')) exit();
/**
 * @copyright Copyright 2008, 2009 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 */

// Define the plugin:
$PluginInfo['QnA'] = array(
   'Name' => 'Q&A',
   'Description' => "Allows users to designate a discussion as a question and then accept one or more of the comments as an answer.",
   'Version' => '1.0b',
   'RequiredApplications' => array('Vanilla' => '2.0.18a1'),
   'Author' => 'Todd Burry',
   'AuthorEmail' => 'todd@vanillaforums.com',
   'AuthorUrl' => 'http://www.vanillaforums.org/profile/todd'
);

class QnAPlugin extends Gdn_Plugin {
   /// PROPERTIES ///

   /// METHODS ///

   public function Setup() {
      $this->Structure();
   }

   public function Structure() {
      Gdn::Structure()
         ->Table('Discussion')
         ->Column('QnA', array('Answered', 'Accepted', 'Rejected'), NULL)
         ->Set();

      Gdn::Structure()
         ->Table('Comment')
         ->Column('QnA', array('Accepted', 'Rejected'), NULL)
         ->Set();

      Gdn::SQL()->Replace(
         'ActivityType',
         array('AllowComments' => '0', 'RouteCode' => 'question', 'Notify' => '1', 'Public' => '0', 'ProfileHeadline' => '', 'FullHeadline' => ''),
         array('Name' => 'QuestionAnswer'));
      Gdn::SQL()->Replace(
         'ActivityType',
         array('AllowComments' => '0', 'RouteCode' => 'answer', 'Notify' => '1', 'Public' => '0', 'ProfileHeadline' => '', 'FullHeadline' => ''),
         array('Name' => 'AnswerAccepted'));
   }


   /// EVENTS ///

   /**
    *
    * @param Gdn_Controller $Sender
    * @param array $Args
    */
   public function Base_CommentOptions_Handler($Sender, $Args) {
      $Discussion = GetValue('Discussion', $Args);
      $Comment = GetValue('Comment', $Args);

      if (!$Discussion || !$Comment || !GetValue('Type', $Discussion) == 'question')
         return;

      // Check permissions.
      $CanAccept = Gdn::Session()->CheckPermission('Garden.Moderation.Manage');
      $CanAccept |= Gdn::Session()->UserID == GetValue('InsertUserID', $Discussion) && Gdn::Session()->UserID != GetValue('InsertUserID', $Comment);
      
      if (!$CanAccept)
         return;

      $QnA = GetValue('QnA', $Comment);
      if ($QnA)
         return;

      // Write the links.
      $Query = http_build_query(array('commentid' => GetValue('CommentID', $Comment), 'tkey' => Gdn::Session()->TransientKey()));

      echo '<span>'.Anchor(T('Accept', 'Accept'), '/discussion/qna/accept?'.$Query, array('class' => 'QnA-Yes', 'title' => T('Accept this answer.'))).'</span>'.
         '<span>'.Anchor(T('Reject', 'Reject'), '/discussion/qna/reject?'.$Query, array('class' => 'QnA-No', 'title' => T('Reject this answer.'))).'</span>';

      static $InformMessage = TRUE;

      if ($InformMessage && Gdn::Session()->UserID == GetValue('InsertUserID', $Discussion) && in_array(GetValue('QnA', $Discussion), array('', 'Answered'))) {
         $Sender->InformMessage(T('Click accept or reject beside an answer.'), 'Dismissable');
         $InformMessage = FALSE;
      }
   }

   public function Base_CommentInfo_Handler($Sender, $Args) {
      $Type = GetValue('Type', $Args);
      if ($Type != 'Comment')
         return;

      $QnA = GetValueR('Comment.QnA', $Args);

      if ($QnA && ($QnA == 'Accepted' || Gdn::Session()->CheckPermission('Garden.Moderation.Manage'))) {
         $Title = sprintf(T('This answer was %s.'), T('Q&A '.$QnA, $QnA));
         echo '<div class="QnA-Box QnA-'.$QnA.'" title="'.htmlspecialchars($Title).'"><div>'.$Title.'</div></div>';
      }
   }

   public function CommentModel_BeforeNotification_Handler($Sender, $Args) {
      $ActivityModel = $Args['ActivityModel'];
      $Comment = (array)$Args['Comment'];
      $CommentID = $Comment['CommentID'];
      $Discussion = (array)$Args['Discussion'];

      $ActivityID = $ActivityModel->Add(
         $Comment['InsertUserID'],
         'QuestionAnswer',
         Anchor(Gdn_Format::Text($Discussion['Name']), "discussion/comment/$CommentID/#Comment_$CommentID"),
         $Discussion['InsertUserID'], 
         '',
         "/discussion/comment/$CommentID/#Comment_$CommentID");
      $ActivityModel->QueueNotification($ActivityID, '', 'first');
   }

   /**
    * @param CommentModel $Sender
    * @param array $Args
    */
   public function CommentModel_BeforeUpdateCommentCount_Handler($Sender, $Args) {
      $Discussion =& $Args['Discussion'];

      // Mark the question as answered.
      if (strtolower($Discussion['Type']) == 'question' && !$Discussion['Sink'] && !in_array($Discussion['QnA'], array('Answered', 'Accepted')) && $Discussion['InsertUserID'] != Gdn::Session()->UserID) {
         $Sender->SQL->Set('QnA', 'Answered');
      }
   }

   /**
    *
    * @param DiscussionController $Sender
    * @param array $Args
    */
   public function DiscussionController_QnA_Create($Sender, $Args) {
      $Comment = Gdn::SQL()->GetWhere('Comment', array('CommentID' => $Sender->Request->Get('commentid')))->FirstRow(DATASET_TYPE_ARRAY);
      if (!$Comment)
         throw NotFoundException('Comment');

      $Discussion = Gdn::SQL()->GetWhere('Discussion', array('DiscussionID' => $Comment['DiscussionID']))->FirstRow(DATASET_TYPE_ARRAY);

      // Check for permission.
      if (!(Gdn::Session()->UserID == GetValue('InsertUserID', $Discussion) || Gdn::Session()->CheckPermission('Garden.Moderation.Manage'))) {
         throw PermissionException('Garden.Moderation.Manage');
      }
      if (!Gdn::Session()->ValidateTransientKey($Sender->Request->Get('tkey')))
         throw PermissionException();

      switch ($Args[0]) {
         case 'accept':
            $QnA = 'Accepted';
            break;
         case 'reject':
            $QnA = 'Rejected';
            break;
      }

      if (isset($QnA)) {
         // Update the comment.
         Gdn::SQL()->Put('Comment', array('QnA' => $QnA), array('CommentID' => $Comment['CommentID']));

         // Update the discussion.
         if (!$Discussion['QnA'] || $Discussion['QnA'] = 'Answered')
            Gdn::SQL()->Put('Discussion', array('QnA' => $QnA), array('DiscussionID' => $Comment['DiscussionID']));

         // Record the activity.
         AddActivity(
            Gdn::Session()->UserID,
            'AnswerAccepted',
            Anchor(Gdn_Format::Text($Discussion['Name']), "/discussion/{$Discussion['DiscussionID']}/".Gdn_Format::Url($Discussion['Name'])),
            $Comment['InsertUserID'],
            "/discussion/comment/{$Comment['CommentID']}/#Comment_{$Comment['CommentID']}",
            TRUE
         );
      }

      Redirect("/discussion/comment/{$Comment['CommentID']}#Comment_{$Comment['CommentID']}");
   }

   public function DiscussionModel_BeforeGet_Handler($Sender, $Args) {
      if ($QnA = Gdn::Request()->Get('qna')) {
         $Args['Wheres']['QnA'] = $QnA;
      }
   }

   /**
    *
    * @param DiscussionModel $Sender
    * @param array $Args
    */
   public function DiscussionModel_BeforeSaveDiscussion_Handler($Sender, $Args) {
      $Sender->Validation->ApplyRule('Type', 'Required', T('Choose either whether you want to ask a question or start a discussion.'));
   }

   public function Base_BeforeDiscussionMeta_Handler($Sender, $Args) {
      $Discussion = $Args['Discussion'];

      if (!GetValue('Type', $Discussion) == 'question')
         return;

      $QnA = GetValue('QnA', $Discussion);
      $Title = '';
      switch ($QnA) {
         case '':
         case 'Rejected':
            $Text = 'Question';
            $QnA = 'Question';
            break;
         case 'Answered':
            $Text = 'Answered';
            if (GetValue('InsertUserID', $Discussion) == Gdn::Session()->UserID) {
               $QnA = 'Answered Alert';
               $Title = ' title="'.T("Someone's answered your question. You need to accept/reject the answer.").'"';
            }
            break;
         case 'Accepted':
            $Text = 'Answered';
            $Title = ' title="'.T("This question's answer has been accepted.").'"';
            break;
         default:
            $QnA = FALSE;
      }
      if ($QnA) {
         echo '<span class="Tag QnA-Tag-'.$QnA.'"'.$Title.'>'.T("Q&A $Text", $Text).'</span> ';
      }
   }

   /**
    * @param Gdn_Controller $Sender
    * @param array $Args
    */
   public function NotificationsController_BeforeInformNotifications_Handler($Sender, $Args) {
      $Path = trim($Sender->Request->GetValue('Path'), '/');
      if (preg_match('`^(vanilla/)?discussion[^s]`i', $Path))
         return;

      // Check to see if the user has answered questions.
      $Count = Gdn::SQL()->GetCount('Discussion', array('Type' => 'Question', 'InsertUserID' => Gdn::Session()->UserID, 'QnA' => 'Answered'));
      if ($Count > 0) {
         $Sender->InformMessage(FormatString(T('You have answered questions', 'You have answered <a href="{/discussions/mine?qna=Answered,url}">questions</a>. Make sure you accept/reject the answers.')), '');
      }
   }

   public function PostController_BeforeBodyInput_Handler($Sender, $Args) {
      $Form = $Sender->Form;

      echo '<div class="P">',
         T('You can either ask a question or start a discussion.', 'You can either ask a question or start a discussion. Choose what you want to do below.'),
         '</div>';

      echo '<div class="P">',
         $Form->RadioList('Type', array('Question' => 'Question', 'Discussion' => 'Discussion')),
      '</div>';
   }
}
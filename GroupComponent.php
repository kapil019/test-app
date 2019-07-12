<?php
namespace App\Component;
use App\Model\GroupModel as GroupModel;
use App\Model\GroupUserModel as GroupUserModel;
use App\DTO\GroupDTO as GroupDTO;
use App\DTO\GroupUserDTO as GroupUserDTO;
use App\Exception\GroupException as GroupException;
use App\Exception\FlipHTTPException as FlipHTTPException;
use App\Exception\BackendException as BackendException;
use App\DAO\GroupDAO as GroupDAO;
use App\Request\AddGroupRequest as GroupRequest;
use App\Response\AddGroupResponse as GroupResponse;
use App\HTTP\GroupSRO as GroupSRO;
use App\UTIL\Util as Util;
use App\HTTP\MiniGroupSRO as MiniGroupSRO;
use App\HTTP\GroupUserSRO as GroupUserSRO;
use App\Translators\GroupTranslators as GroupTranslators;
use App\Validator\GroupValidators as GroupValidators;
use App\External\User\Request\GetUserByUuidRequest as GetUserByUuidRequest;
use App\External\User\UserService as UserService;
use App\External\User\Request\GetUserRoleRequest as GetUserRoleRequest;
use App\External\User\Request\GetUserRelationRequest as GetUserRelationRequest;
use App\External\School\SchoolService as SchoolService;
use Symfony\Component\Serializer\Serializer as Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder as XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder as JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer as GetSetMethodNormalizer;
/**
 * @file
 * Class groupComponent
 * This class is used for api's of group.
 * @author Gaurav Sengar
 * @email gaurav@incaendo.com
 * @copyright (c) 2016, Fliplearn.
 */
class GroupComponent {
  private $logger;
  private $translators;
  private $validator;
  private $util;
  public function __construct() {
    $this->logger = \Slim\Registry::getLogger();
    $this->translators = new GroupTranslators();
    $this->validator = new GroupValidators();
    $this->util = new Util();
    
    $encoders = array(new XmlEncoder(), new JsonEncoder());
    $normalizers = array(new GetSetMethodNormalizer());
    $this->serializer = new Serializer($normalizers, $encoders);
  }
  /**
   * Function addGroup.
   * This function is used to add group.
   * 
   * @param request
   *    Parameter $groupName, $groupCode, $ownerUUID
   *
   * @return response
   *   Return object.
   * 
   * @author Gaurav Sengar <gaurav@incanedo.com>
   */
  public function addGroup(GroupSRO $groupSRO) {
    $groupCode = $groupSRO->getGroupCode();
    if (empty($groupCode)) {
      $groupCode = $this->util->generateCode();
      $groupSRO->setGroupCode($groupCode);
      /* $expSchool= explode("-",$groupSRO->getSchoolCode());
        $groupSRO->setSchool(isset($expSchool[0])?$expSchool[0]:null);
        $groupSRO->setAyid(isset($expSchool[1])?$expSchool[1]:null); */
    }
    $this->validator->validateAddGroup($groupSRO);
    try {
      $groupDaoObj = new GroupDAO();
      $groupCodeExist = $groupDaoObj->getGroupDetailsByGroupCode($groupCode);
      if (!empty($groupCodeExist)) {
        throw(new \InvalidArgumentException("Group Code already exists", 409));
      }
      if($groupSRO->getGroupTypeCode() == SCG) {
        $groupNameExist = $groupDaoObj->getGroupDetailsByGroupName($groupSRO);
        if (!empty($groupNameExist)) {
            throw(new \InvalidArgumentException("Custom group already exists with this name", 409));
        }
      }
      $requestGroupDTO = $this->translators->populateGroupDTOFromGroupSRO($groupSRO);
      $requestGroupDTO->setIsActive(ACTIVE);
      $requestGroupModel = $this->translators->populateGroupModelFromDTO($requestGroupDTO);
      $groupTypeCode = strtoupper($requestGroupDTO->getGroupTypeCode());
      
      // @Fliplearn 3.0 : Check added for all teacher not to be added in ATG group as in new architecture ATG group is removed
      $groupNotExist = array(ALLTEACHER, SUBJECT);
      if (in_array($groupTypeCode, $groupNotExist)){ 
         throw(new \InvalidArgumentException($groupTypeCode." Group can not be add", 409)); 
      }
      
      /* GroupTypeModel */$groupTypeModel = $groupDaoObj->getGroupTypeByGroupTypeCode($groupTypeCode);
      if (is_array($groupTypeModel) && empty($groupTypeModel)) {
        throw(new \InvalidArgumentException("Group Type does not exist", 409));
      }
      $requestGroupModel->setGroupTypeId($groupTypeModel[0]->getId());
      $schoolCode = $requestGroupDTO->getSchoolCode();
      $ayid = $requestGroupDTO->getAyid();
      /*
       * @Fliplearn 3.0 : ATG group related changes
       * $groupTypeArr = array(SCHOOL, ALLTEACHER);
       * 
       */
      $groupTypeArr = array(SCHOOL);
      if ($groupTypeCode == APPLICATIONUSER) {
        $groupCodeExist = $groupDaoObj->getGroupsByGroupType($groupTypeModel[0]->getId(), null, $ayid);
        if (!empty($groupCodeExist)) {
          throw(new \InvalidArgumentException("Group already exist for groupTypeCode : " . $groupTypeCode, 409));
        }
      }
      if (in_array($groupTypeCode, $groupTypeArr)) {
        $groupCodeExist = $groupDaoObj->getGroupsByGroupType($groupTypeModel[0]->getId(), $schoolCode, $ayid);
        if (!empty($groupCodeExist)) {
          throw(new \InvalidArgumentException("Group already exist for groupTypeCode : " . $groupTypeCode, 409));
        }
      }
      // @Fliplearn 3.0 : Check added for all teacher not to be added in ATG group as in new architecture ATG group is removed
      if($groupTypeCode == ALLTEACHER){
         throw(new \InvalidArgumentException("ATG group type cannot be created", 409)); 
      }
      /* GroupDAO */
      $responseGroupModel = $groupDaoObj->addGroupDAO($requestGroupModel);
      /*
        $activeDate = date('Y-m-d');
        $deactiveDate = date('Y-m-d', strtotime('+1 years'));
        $groupUserDto = $this->translators->populateUserDTOFromParmeter($responseGroupModel->getGroupId(), $responseGroupModel->getOwnerUuid(), $responseGroupModel->getActionedBy(), $activeDate, $deactiveDate);
        $groupUserModel = $this->translators->populateGroupUserModelFromDto($groupUserDto);
        $groupUserModel->setIsActive(ACTIVE);
        $groupUserModel->setGroupRoleId(GOWN); //GOWN  - Group Owner
        $responseGroupModel->unsetGroupUsers(NULL);
        $groupUserModel->setGroup($responseGroupModel);
        $groupDaoObj->addUserDAO($groupUserModel);
       */
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:addGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    $responseGroupDTO = $this->translators->populateDTOFromGroupModel($responseGroupModel);
    $responseGroupDTO->setGroupTypeCode($groupTypeModel[0]->getGroupTypeCode());
    $responseGroupSRO = $this->translators->populateSROFromDTO($responseGroupDTO);
    return $responseGroupSRO;
  }
  public function updateGroup(GroupSRO $groupSRO) {
    $this->validator->validateSetGroup($groupSRO);
    try {
      $status = false;
      $requestGroupDTO = $this->translators->populateGroupDTOFromGroupSRO($groupSRO);
      // $requestGroupModel = $this->translators->populateGroupModelEditFromDTO($requestGroupDTO);
      $groupCode = $groupSRO->getGroupCode();
      $groupDaoObj = new GroupDAO();
      $groupDetails = $groupDaoObj->getGroupDetailsByGroupCode($groupCode);
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("This Group Code does not exist!", 409));
      }
      if ($requestGroupDTO->getGroupName()) {
        $groupDetails[0]->setGroupName($requestGroupDTO->getGroupName());
      }
      if ($requestGroupDTO->getActionedBy()) {
        $groupDetails[0]->setActionedBy($requestGroupDTO->getActionedBy());
      }
      if ($requestGroupDTO->getDescription()) {
        $groupDetails[0]->setDescription($requestGroupDTO->getDescription());
      }
      if ($requestGroupDTO->getGroupLogo()) {
        $groupDetails[0]->setGroupLogo($requestGroupDTO->getGroupLogo());
      }
      if ($requestGroupDTO->getDisplayName()) {
        $groupDetails[0]->setDisplayName($requestGroupDTO->getDisplayName());
      }
      /* GroupDAO */$responseGroupModel = $groupDaoObj->editGroupDAO($groupDetails[0]);
      if ($responseGroupModel) {
        $status = true;
      }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:setGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    $responseGroupDTO = $this->translators->populateDTOFromGroupModel($responseGroupModel);
    $responseGroupSRO = $this->translators->populateSROFromDTO($responseGroupDTO);
    return $status;
  }
  /**
   * Function checkRollNumberForSection.
   * This function is used to check if roll number for a section exist or not.
   *
   * @param $groupCode string
   *   Section group code.
   * @param $groupId integer
   *   Section group id.
   * @param $rollNumber integer
   *   Student roll number
   *
   * @author Naman Kumar Srivastava <naman@incaendo.com>
   */
  private function checkRollNumberForASection($groupCode, $groupId, $rollNumber = null) {
    /* if (empty($rollNumber)) {
      throw new \InvalidArgumentException('Roll number cannot be empty', 409);
      } */
    try {
      if ($rollNumber != NULL) {
        $groupDAO = new GroupDAO();
        $groupUserDetails = $groupDAO->getGroupUserByGroupIdAndRollNumber($groupId, $rollNumber);
        if (!empty($groupUserDetails)) {
          throw new \InvalidArgumentException('Roll number: ' . $rollNumber . ' already exist for groupCode: ' . $groupCode, 409);
        }
      }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:checkRollNumberForASection:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
  }
  /**
   * Function blockOrUnblockUsersByGroupCode.
   * This function is block/unblock Users Status To Group.
   * 
   * @param request
   *    Array
   *
   * @return response
   *   Return json.
   * 
   * @author Gaurav Sengar <gaurav@incanedo.com>
   * 
   * @param type $request
   * @return \App\Component\response
   */
  public function blockOrUnblockUsersByGroupCode(GroupSRO $groupSRO) {
    $this->validator->validateBlockOrUnblockGroupUsers($groupSRO);
    try {
      $groupCode = $groupSRO->getGroupCode();
      $uuid_arr = $groupSRO->getGroupUsers();
      if (count($uuid_arr) >= DB_LIMIT) {
        throw(new \InvalidArgumentException("UUID's cannot be more than " . DB_LIMIT, 409));
      }
      $actioned_by = $groupSRO->getActionedBy();
      $userStatus = strtolower($groupSRO->getIsActive());
      $groupDaoObj = new GroupDAO();
      $userList['status'] = 'false';
      $userList['successUser'] = array();
      $userList['failedUser'] = array();
      $groupDetails = $groupDaoObj->getGroupDetailsByGroupCode($groupCode);
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("Requested Group not found", 409));
      }
      $grpModel = $groupDetails[0];
      $grpId = $grpModel->getGroupId();
      $groupUsersDetail = $groupDaoObj->getexistUserInGroup($grpId, $uuid_arr);
      $grp_user_arr = array();
      foreach ($groupUsersDetail as $groupUserDetail) {
        $grp_user_arr[] = $groupUserDetail->getUuid();
      }
      $users_not_in_group = array_diff($uuid_arr, $grp_user_arr);
      foreach ($users_not_in_group as $user_not_in_group) {
        $userList['failedUser'][] = $user_not_in_group;
      }
      if ($userStatus == 'true') {
        $userStatus = 1;
      } else {
        $userStatus = 0;
      }
      $statusCheck = 0;
      foreach ($groupUsersDetail as $grpUser) {
        $grpUser->setIsActive($userStatus);
        $grpUser->setActionedBy($actioned_by);
        $grpModel->setGroupUsers($grpUser);
        try {
          $status = $groupDaoObj->updateGroupUserStatus($grpModel);
          if ($status == 'true') {
            $statusCheck++;
          }
          $userList['successUser'][] = $grpUser->getUuid();
        } catch (Exception $e) {
          array_push($userList['failedUser'][], $grpUser->getUuid());
          $grpUser->getUuid() . " error " . $e->getMessage();
        }
      }
      //echo $statusCheck;die;
      if ($statusCheck > 0) {
        $userList['status'] = 'true';
      }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:addUserToGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $userList;
  }
  /**
   * Function getUsersMappingByGroupCode.
   * This function is used to get group users SRO array.
   * 
   * @param request
   *   Array of Group Codes
   *
   * @return response
   *   Return array.
   * 
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  public function getUsersMappingByGroupCode($grpCode, $pageNum, $pageSize) {
    $this->validator->validateGetUsersByGroupCode($grpCode, $pageNum, $pageSize);
    $condition = $this->getPageLimit($pageNum, $pageSize);
    try {
      $groupDaoObj = new GroupDAO();
      $groupUsers = $groupDaoObj->getUsersByGroupCode($grpCode);
      if ($groupUsers == 'false') {
        throw new groupException('Records are not present for this Group Code.', 409);
      }
      $groupUsersCnt = count($groupUsers);
      $totalPages = ceil($groupUsersCnt / $pageSize);
      if ($pageNum > $totalPages) {
        throw new groupException('Records are not present for this Page Number or Group Code.', 409);
      }
      if ($groupUsersCnt > MAX_LIMIT) {
        throw new groupException('Please set lower Page Size Limit.', 409);
      }
      $outputUsers = array_slice($groupUsers, $condition['recordsFrom'], $pageSize);
      /* GroupModel */ $groupsDetails = $groupDaoObj->getGrpUsersByGroupCode($grpCode);
      $cnt = count($groupsDetails);
      if (!empty($groupsDetails) && $cnt > 0) {
        $usrsGroupSROArray = array();
        foreach ($groupsDetails as $grpDetails) {
          $responseGroupDTO = $this->translators->populateUsersDTOFomGrpModel($grpDetails);
          $usrsGroupSROArray[] = $this->translators->populateUsersSROFromDTO($responseGroupDTO, $outputUsers, $pageNum, $pageSize);
        }
      } else {
        throw new groupException('Record not found with getUsersMappingByGroupCode request.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getUsersMappingByGroupCode:" . $e->getMessage());
      throw new \GroupException($e->getMessage(), 500);
    }
    return $usrsGroupSROArray;
  }
  /**
   * Function getGroupByUUID.
   * This function is used to list all group name by UUID.
   * 
   * @param request
   *    UUID as int
   *
   * @return response
   *   Return json.
   * 
   * @author Prakhar Saxena <prakhar.saxena@infogain.com>
   * 
   * @return \App\Component\response
   * @throws groupException
   */
  public function getGroupsByUUID($UUID) {
    $response = "";
    $userGroupDetails = groupDAO::getGroupsByUUIDDAO($UUID);
    $cnt = count($userGroupDetails);
    if (!empty($userGroupDetails) && $cnt > 0) {
      foreach ($userGroupDetails as $key => $userGroupDetail) {
        $response[$key] = $this->getGroupsByUUIDDTO($userGroupDetail);
      }
    } else {
      throw new groupException('Recode not found with getUsersByGroupCode request.', 409);
    }
    return $response;
  }
  /**
   * Function getGroupsUsersMappingByUsersUuids.
   * This function is used to list all group name by UUID.
   * 
   * @param request
   *    UUID as array
   *
   * @return response
   *   Return json.
   * 
   * @author Prakhar Saxena <prakhar.saxena@fliplearn.com>
   * 
   * @return \App\Component\response
   * @throws groupException
   */
  public function getGroupsUsersMappingByUsersUuids($uuids) {
    $this->validator->validateUserUuids($uuids);
    $usersUuidsSRO = array();
    $uuids_arr = $this->getUuids($uuids);
    /* there should be a limit to no. of login IDs you can send in the request */
    try {
      $groupDAO = new GroupDAO();
      /* GroupUserModel */ $userGroupDetails = $groupDAO->getGroupsUsersMappingByUsersUuids($uuids_arr);
      $cnt = count($userGroupDetails);
      if ($cnt > 0) {
        foreach ($userGroupDetails as $userGroupDetail) {
          /* GroupUserDTO */ $userGroupDetailDTO = $this->translators->populateGroupsUsersMappingDTOFromModel($userGroupDetail);
          /* GroupUsersRoleModel */
          $groupUsersRoleDetails = $groupDAO->getGroupRoleById($userGroupDetail->getGroupRoleId());
          $userGroupDetailDTO->setGroupRoleCode($groupUsersRoleDetails[0]->getGroupRoleCode());
          $usersUuidsSRO[] = $this->translators->populateGroupsUsersMappingSROFromDTO($userGroupDetailDTO);
        }
      } else {
        throw new GroupException('No Record found with getGroupsUsersMappingByUsersUuids request.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getGroupsUsersMappingByUsersUuids:" . $e->getMessage(), 500);
      throw new GroupException($e->getMessage());
    }
    return $usersUuidsSRO;
  }
  private function getUuids($uuids) {
    $uuids_arr = explode('|', $uuids);
    return $uuids_arr;
  }
  /**
   * Function getGroupsByGroupType.
   * This function is used to get group details SRO object.
   * 
   * @param request
   * Group Type, School Code
   *
   * @return response
   *   Return SRO Object.
   * 
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  public function getGroupsByGroupType($groupType, $schoolCode, $ayid) {
    $this->validator->validateGetGroupsByGroupType($schoolCode, $ayid);
    try {
      $groupDAO = new GroupDAO();
      if ($groupType != null) {
        /* GroupTypeModel */$groupTypeModel = $groupDAO->getGroupTypeByGroupTypeCode($groupType);
        if (is_array($groupTypeModel) && empty($groupTypeModel)) {
          throw(new \InvalidArgumentException("Group Type does not exist", 409));
        } else {
          $grpType = $groupTypeModel[0]->getId();
        }
      } else {
        $grpType = null;
      }
      /* GroupModel */ $groupsByGroupType = $groupDAO->getGroupsByGroupType($grpType, $schoolCode, $ayid);
      $cnt = count($groupsByGroupType);
      if ($cnt > 0) {
        foreach ($groupsByGroupType as $groupByGroupType) {
          /* GroupDTO */ $groupsByGroupTypeDetailDTO = $this->translators->populateDTOFromGroupModel($groupByGroupType);
          $groupsByGroupTypeDetailSRO[] = $this->translators->populateSROFromDTO($groupsByGroupTypeDetailDTO);
        }
      } else {
        throw new GroupException('No Record found with getGroupsByGroupType request.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getGroupsByGroupType:" . $e->getMessage(), 500);
      throw new GroupException($e->getMessage());
    }
    return $groupsByGroupTypeDetailSRO;
  }
  /**
   * Data Transfer Object 
   * This function will return data transfer object for getGroupBYUUID.
   * 
   * @author Prakhar Saxena <prakhar.saxena@infogain.com>
   */
  private function getGroupsByUUIDDTO($group) {
    $groupUserDTO = new GroupUserDTO();
    $groupUserDTO->setUuid($group->getUuid());
    $groupUserDTO->setGroupCode($group->getGroup()->getGroupCode());
    $groupUserDTO->setGroupName($group->getGroup()->getGroupName());
    return $groupUserDTO;
  }
  public function isEmpty($request, $required) {
    $isValid = true;
    if (!empty($request) && !empty($required)) {
      foreach ($required as $code => $key) {
        if (empty($request[$key])) {
          $this->response['error'][] = array(
            'error_code' => $code,
          );
          $isValid = false;
        }
      }
    } else {
      $isValid = false;
    }
    return $isValid;
  }
  /**
   * Function setStatusGroup.
   *
   * @param request
   *   GroupCode, GroupStatus
   *
   * @return response
   *   Return boolean.
   * 
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  public function setStatusGroup($setGroupStatusRequest) {
    $grpStatus = $setGroupStatusRequest->getIsActive();
    $grpCode = $setGroupStatusRequest->getGroupCode();
    $actionedBy = $setGroupStatusRequest->getActionedBy();
    $this->validator->validateGrpCodeAndStatus($grpCode, $grpStatus, $actionedBy);
    try {
      $groupDaoObj = new GroupDAO();
      $groupTypeId = $groupDaoObj->getGroupTypeByGroupCode($grpCode);
      if ($groupTypeId == 'false') {
        throw(new \InvalidArgumentException("No Such Group Exists With This Code : " . $grpCode, 409));
      }
      $groupTypeCode = $groupDaoObj->getGroupTypeByGroupTypeId($groupTypeId);
      if (!empty($groupTypeCode[0]->getGroupTypeCode()) && ($groupTypeCode[0]->getGroupTypeCode() == CUSTOM)) {
        $group = $groupDaoObj->getCustomGroupDetailsByGroupCode($grpCode);
        $groupModel = $group[0];
        if ($groupModel == null) {
          throw(new \InvalidArgumentException("No Such Group Exists With This Code : " . $grpCode, 409));
        }
        if ($grpStatus == 'true') {
          $grpStatus = ACTIVE;
        } else if ($grpStatus == 'false') {
          $grpStatus = INACTIVE;
        }
        $groupModel->setIsActive($grpStatus);
        $groupModel->setActionedBy($actionedBy);
        $groupDaoObj->updateGroup($groupModel);
      } elseif ($groupTypeCode != CUSTOM) {
        throw(new \InvalidArgumentException("This is not a Custom User Group", 409));
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:setStatusGroup:" . $e->getMessage());
      throw new \GroupException($e->getMessage(), 500);
    }
    return true;
  }
  /**
   * Function getGrpDetailsMappingByGroupCode.
   * This function is used to get group details SRO array.
   * 
   * @param request
   *   Array of Group Codes
   *
   * @return response
   *   Return array of group details.
   * 
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  public function getGrpDetailsMappingByGroupCode($grpCode) {
    $this->validator->validateGrpCode($grpCode);
    $grpCode = explode(",", $grpCode);   
    $groupCnt = count($grpCode);
    if($groupCnt > 10){
      throw new groupException('GroupCode count can not be more then 10', 409);
    }
    try {
      $groupDaoObj = new GroupDAO();
      /* GroupModel */ $groupDetails = $groupDaoObj->getGrpUsersByGroupCode($grpCode);
      $cnt = count($groupDetails);
      if (!empty($groupDetails) && $cnt > 0) {
        $usrsGroupSROArray = array();
        foreach ($groupDetails as $grpDetails) {
          /* GroupTypeModel */$groupTypeModel = $groupDaoObj->getGroupTypeByGroupTypeId($grpDetails->getGroupTypeId());
          $responseGroupDTO = $this->translators->populateUsersDTOFomGrpModel($grpDetails);
          $responseGroupDTO->setGroupTypeCode($groupTypeModel[0]->getGroupTypeCode());
          $usrsGroupSROArray[] = $this->translators->populateGrpDetailsSROFromDTO($responseGroupDTO);
        }
      } else {
        throw new groupException('Record not found with getUsersMappingByGroupCode request.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getUsersMappingByGroupCode:" . $e->getMessage());
      throw new \GroupException($e->getMessage(), 500);
    }
    return $usrsGroupSROArray;
  }
  /**
   * Function updateRoleByGroupCodeRequest.
   * This function is used to change role.
   * 
   * @param request
   *    Parameter $groupcode, $uuid, $createdBy
   *
   * @return response
   *   Return object.
   * 
   * @author Rahul Dubey <rahul.dubey@incanedo.com>
   */
  public function updateRoleByGroupCode(GroupUserSRO $groupUserSRO) {
    $this->validator->validateUpdateRoleByGroupCode($groupUserSRO);
    try {
      $groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
      $groupDaoObj = new GroupDAO();
      $groupDetails = $groupDaoObj->getGroupDetailsByGroupCode($groupUserDTO->getGroupCode()); // get Group Id by groupCode from DAO
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("Group Code does not exist!", 409));
      }
      /* if ($groupDetails[0]->getGroupTypeId() == 6) {
        throw(new \InvalidArgumentException("This group is not custom group!", 3308));
        } */
      if ($groupDetails[0]->getIsActive() == 0) {
        throw(new \InvalidArgumentException("This group is not active!", 409));
      }
      $userGroupDetails = $groupDaoObj->getexistUserInGroup($groupDetails[0]->getGroupId(), $groupUserDTO->getUuid()); // check user id extist this group id 
      if (!$userGroupDetails) {
        throw(new \InvalidArgumentException("uuid does not exist this group!", 409));
      }
      $groupRoleDetails = $groupDaoObj->getUserRoleByGroupRoleCode($groupUserDTO->getGroupRoleCode());
      if (count($groupRoleDetails) == 0) {
        throw(new \InvalidArgumentException("Group role code invalid!", 409));
      }
      $userGroupDetails[0]->setGroupRoleId($groupRoleDetails[0]->getId());
      $userGroupDetails[0]->setActionedBy($groupUserDTO->getActionedBy());
      $responseGroupModel = $groupDaoObj->editGroupDAO($groupDetails[0]);
      if ($responseGroupModel) {
        $status = true;
      }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:updateRoleByGroupCodeRequest:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $status;
  }
  /**
   * Function getPageLimit.
   * This function is used to get page limit.
   * 
   * @param request
   * integer page number and page size
   *
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  private function getPageLimit($pageNum, $pageSize) {
    $pageLimit = ($pageSize) ? $pageSize : PAGE_SIZE;
    $pageNumber = ($pageNum) ? $pageNum : PAGE_NUM;
    if ($pageNumber > 1) {
      $recordsFrom = ($pageNumber - 1) * $pageLimit;
    } else {
      $recordsFrom = 0;
    }
    $condition = array(
      'pageLimit' => $pageLimit,
      'recordsFrom' => $recordsFrom
    );
    return $condition;
  }
  /**
   * Function updateGroupUserByGroupCode.
   * This function is used to update Group User By Group Code.
   * 
   * @param request
   *    Parameter $groupCode, $uuid, $schoolRoleCode, $schoolRoleCode, $activeDate, $deactiveDate, $actionedBy
   *
   * @return response
   *   Return Bool<true/false>.
   * 
   * @author Gaurav Sengar <gaurav@incanedo.com>
   */
  public function updateGroupUserByGroupCode(GroupUserSRO $groupUserSRO) {
    $this->validator->validateUpdateGroupUserByGroupCode($groupUserSRO);
    try {
      if ($groupUserSRO->getIsActive() == 'false') {
        $groupUserSRO->setIsActive(0);
      } else if ($groupUserSRO->getIsActive() == 'true') {
        $groupUserSRO->setIsActive(1);
      }
      $groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
//    die("snaejhi");
      $groupDaoObj = new GroupDAO();
      $groupDetails = $groupDaoObj->getGroupDetailsByGroupCode($groupUserDTO->getGroupCode());
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("Group Code does not exist!", 409));
      }
      if ($groupDetails[0]->getIsActive() == INACTIVE) {
        throw(new \InvalidArgumentException("Deactivated Group", 409));
      }
      $userGroupDetails = $groupDaoObj->getexistUserInGroup($groupDetails[0]->getGroupId(), $groupUserDTO->getUuid());
      if (!$userGroupDetails) {
        throw(new \InvalidArgumentException("Invalid UUID", 409));
      }
      if (!empty($groupUserDTO->getGroupRoleCode()) && $groupUserDTO->getGroupRoleCode() != 'null') {
        $groupRoleDetails = $groupDaoObj->getUserRoleByGroupRoleCode($groupUserDTO->getGroupRoleCode());
        if (count($groupRoleDetails) == 0) {
          throw(new \InvalidArgumentException("Group role code invalid!", 409));
        }
        $userGroupDetails[0]->setGroupRoleId($groupRoleDetails[0]->getId());
      }
      if (!empty($groupUserDTO->getActiveDate()) && $groupUserDTO->getActiveDate() != "null") {
        $userGroupDetails[0]->setActiveDate($groupUserDTO->getActiveDate());
      }
      if (!empty($groupUserDTO->getDeactiveDate()) && $groupUserDTO->getDeactiveDate() != "null") {
        $userGroupDetails[0]->setDeactiveDate($groupUserDTO->getDeactiveDate());
      }
      $userGroupDetails[0]->setActionedBy($groupUserDTO->getActionedBy());
      if (!empty($groupUserDTO->getRollNumber()) && $groupUserDTO->getRollNumber() != "null") {
        $userGroupDetails[0]->setRollNumber($groupUserDTO->getRollNumber());
      }
      if (!is_null($groupUserDTO->getIsActive()) && is_int($groupUserDTO->getIsActive())) {
        $userGroupDetails[0]->setIsActive($groupUserDTO->getIsActive());
      }
      //print_r($userGroupDetails[0]->getUuid());die;
      $responseGroupModel = $groupDaoObj->editGroupDAO($groupDetails[0]);
      $response = array();
      if ($responseGroupModel) {
       // if($groupUserSRO->getReturnId() == TRUE) {
          foreach ($userGroupDetails[0]->getGroupUserRoles() as $groupUserRoleModel) {
           // if($groupUserRoleModel->getSchoolRoleCode() == 'STU') {
              $id = $groupUserRoleModel->getId();
              $this->solrUpdateData($id, $userGroupDetails[0]);
            //  break;

           // }
          }
          $response['id'] = $id;
       // } else {
       //   $id = NULL;
       // }
        $response['status'] = true;
      }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:updateRoleByGroupCodeRequest:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $response;
  }
  /**
   * Function getSearchGroups.
   * This function is used get Search Groups.
   * 
   * @param request
   *    UUID, schoolCode, groupType
   *
   * @return response
   *   Return json.
   * 
   * @author Deepak Soni <deepak.soni@incaendo.com>
   * 
   * @return \App\Component\response
   * @throws groupException
   */
  public function getSearchGroups(\App\HTTP\GetProfileListForUser $searchGroupsSRO, $pageNum, $pageSize, $noPagination = 'true') {
  //  print_r($searchGroupsSRO); die;
    $searchDTO = $this->translators->populateUserListDTOFromSRO($searchGroupsSRO);
    return $this->getCommonSearch($searchDTO, $pageNum, $pageSize, $noPagination);
  }
  private function getCommonSearch(\App\DTO\GetProfileListForUserDTO $searchCriteriaDTO, $pageNum, $pageSize, $noPagination = 'true') {
  
    if ($noPagination == 'true') {
      $this->validator->validatePageNumAndPageSize($pageNum, $pageSize);
    }
    try {
      $condition = $this->getPageLimit($pageNum, $pageSize);
      $userService = new UserService;
      if ($noPagination == 'true') {
        $userListDetailsData = $userService->getUserProfileBySolr($searchCriteriaDTO, $condition['recordsFrom'], $condition['pageLimit']);
        
        $cnt = isset($userListDetailsData['response']['numFound']) ? $userListDetailsData['response']['numFound'] : 0;
        if($searchCriteriaDTO->getIsInGroup()=='true'){
         $counntRecords = count($userListDetailsData['facet_counts']['facet_pivot']['pivot1']);
        }else{
         $counntRecords = count($userListDetailsData['response']['docs']);
        }
        
        $totalPages = ceil($cnt / $pageSize);
        if ($pageNum > $totalPages) {
          throw new GroupException('Records are not present for this Page Number.', 409);
        }
        if ($counntRecords > MAX_LIMIT) {
          throw new GroupException('Please set lower Page Size Limit.', 409);
        }
      } else {
        $pageSize = MAX_LIMIT * 5; //solr Max limit
        $userListDetailsData = $userService->getUserProfileBySolr($searchCriteriaDTO, $condition['recordsFrom'], $pageSize);
        $cnt = isset($userListDetailsData['response']['numFound']) ? $userListDetailsData['response']['numFound'] : 0;
        if($searchCriteriaDTO->getIsInGroup()=='true'){
         $counntRecords = count($userListDetailsData['facet_counts']['facet_pivot']['pivot1']);
        }else{
         $counntRecords = count($userListDetailsData['response']['docs']);
        }
      }
      if ($counntRecords <= 0) {
        throw(new \InvalidArgumentException("Record doesn't exist with given criteria :", 409));
      }
      $usersListUuidsSRORET = array();
      
      if($searchCriteriaDTO->getIsInGroup()=='true'){
        
        foreach ($userListDetailsData['facet_counts']['facet_pivot']['pivot1'] as $userListDetails) {
          // print_r($userListDetails['pivot'][0]['pivot'][0]['pivot'][0]['pivot'][0]['pivot'][0]['field']); exit;
                 
          $userListDetailDTO = $this->translators->setDTOBySolrOutputGroup($userListDetails,$userListDetailsData['grouplists']);
         // print_r($userListDetailDTO); exit;
          $usersListUuidsSRO[] = $this->translators->populateUserListSROFromDTO($userListDetailDTO);
        }
      }else{
        $getGURId = $searchCriteriaDTO->getGetGURId();
        foreach ($userListDetailsData['response']['docs'] as $userListDetails) {
          $userListDetailDTO = $this->translators->setDTOBySolrOutput($userListDetails, $getGURId);
          $usersListUuidsSRO[] = $this->translators->populateUserListSROFromDTO($userListDetailDTO);
        }
      }
      
      $usersListUuidsSRORET['srooutput'] = $usersListUuidsSRO;
      $usersListUuidsSRORET['count'] = $cnt;
    } catch (FlipHTTPException $e) {
      $this->logger->error("Exception occured in userComponent createUser function message: " . $e->getMessage());
      throw new BackendException($e->getMessage(), 500);
    }
    return $usersListUuidsSRORET;
  }
  public function getProfileByCode($profileCode) {
    $this->validator->validateProfileByCode($profileCode);
    $profileCodeArr = explode('|', $profileCode);
    try {
      $groupDaoObj = new GroupDAO();
      /* GroupModel */ $profileDetails = $groupDaoObj->getProfileByCode($profileCodeArr);
      $cnt = count($profileDetails);
      if (!empty($profileDetails) && $cnt > 0) {
        $profileArray = array();
        foreach ($profileDetails as $profileDetail) {
          $profileDetailDTO = $this->translators->populateSchoolUserRoleDTOFromModel($profileDetail);
          $profileArray[] = $this->translators->populateSchoolUserRoleSROFromDTO($profileDetailDTO);
        }
      } else {
        throw new GroupException('Your profile might be deactivated. Please try login in again.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getUsersMappingByGroupCode:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $profileArray;
  }
  /** Function getProfileListForUser.
   * @return response
   *  Return json.
   * @author Deepak Soni <deepak.soni@incaendo.com>
   * @return \App\Component\response
   * @throws groupException
   */
  public function getProfileListForUser(\App\HTTP\GetProfileListForUser $searchGroupsSRO, $pageNum, $pageSize) {
    $this->validator->validateProfileListForUser($searchGroupsSRO);
    $searchDTO = $this->translators->populateUserListDTOFromSRO($searchGroupsSRO);
    return $this->getCommonSearch($searchDTO, $pageNum, $pageSize);
  }
  public function getProfilesByBaseCode($profileCode) {
    $this->validator->validateProfileByCode($profileCode);
    try {
      $groupDaoObj = new GroupDAO();
      /* GroupModel */ $profileDetails = $groupDaoObj->getProfilesByBaseCode($profileCode);
      $cnt = count($profileDetails);
      if (!empty($profileDetails) && $cnt > 0) {
        $profileArray = array();
        foreach ($profileDetails as $profileDetail) {
          $profileDetailDTO = $this->translators->populateGroupUserRoleDTOFromModel($profileDetail);
          $profileArray[] = $this->translators->populateProfileSROFromSchoolUserRoleDTO($profileDetailDTO);
        }
      } else {
        throw new GroupException('Your profile might be deactivated. Please try login in again.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getUsersMappingByGroupCode:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $profileArray;
  }
  public function syncParentProfiles($studentUuid, $actionedBy, $schCode, $ayid = NULL) {
    $this->validator->validateLinkUsers($studentUuid, $actionedBy, $schCode);
    try {
      $groupDAO = new GroupDAO();
      $group = $groupDAO->getGroupsByGroupType(3, $schCode, $ayid); // here we get group id fro school  
      if (sizeof($group) <= 0) {
        throw new GroupException('No Group Exists With School Code:' . $schCode, 409);
      }
      if ($studentData = $this->validateStudent($studentUuid, $group[0]->getGroupId())) {
        $parents = $this->getUserRelation($studentUuid, "is_parent_of");
        $studentDetails = $this->getUserDetailsByUuid($studentUuid);
        if (count($studentDetails) > 0) {
          $studentName = $studentDetails->getUser()[0]->getFirstName() . ' ' . $studentDetails->getUser()[0]->getMiddleName() . ' ' . $studentDetails->getUser()[0]->getLastName();
        }
        if (count($parents->getRelations()) <= 0) {
          throw new GroupException('No Parent Exists For Student Uuid:' . $studentUuid, 409);
        }
        // Get student profile details.
        $studentProfileDetails = $groupDAO->getGroupsUsersMappingByUsersUuids($studentUuid);
        if (empty($studentProfileDetails)) {
          throw new GroupException('Profile not found for Student Uuid:' . $studentUuid, 409);
        }
        $studentBaseProfileCode = '';
        foreach ($studentProfileDetails as $groupUserModel) {
          foreach ($groupUserModel->getGroupUserRoles() as $groupUserRoleModel) {
            $studentBaseProfileCode = $groupUserRoleModel->getBaseProfileCode();
            break;
          }
          break;
        }
        if (empty($studentBaseProfileCode)) {
          throw new GroupException('BaseProfileCode not found for Student Uuid:' . $studentUuid, 409);
        }
        $NoProfileForParent = true;
        foreach ($parents->getRelations() as $parentData) {
          $parentUuid = $parentData->getTo();
          $parentDetails = $this->getUserDetailsByUuid($parentUuid);
          if (count($parentDetails) > 0) {
            $parentName = $parentDetails->getUser()[0]->getFirstName() . ' ' . $parentDetails->getUser()[0]->getMiddleName() . ' ' . $parentDetails->getUser()[0]->getLastName();
          }
          $profileName = $parentName . ' is parent of ' . $studentName;
          $parentProfiles = $groupDAO->getGroupIdDAO($group[0]->getGroupId(), $parentUuid);
          if (count($parentProfiles) > 0) {
            foreach ($parentProfiles as $profile) {
              if (count($profile->getGroupUserRoles()) > 0) {
                foreach ($profile->getGroupUserRoles() as $roles) {
                  if ($roles->getSchoolRoleCode() == 'PAR') {
                    $NoProfileForParent = false;
                    //  $releationExist = $groupDAO->checkAlreadyExistsRelation($studentUuid, $roles->getProfileCode());
                    $releationExist = $groupDAO->checkAlreadyExistsRelation($studentUuid, $roles->getGroupUserId());
                    if (count($releationExist) <= 0) {  //--here we check if relation already exists                 
                      if ($roles->getRefCode() != '') {
                        $profileCode = $this->util->generateCode(10);
                        $schoolUserRoleDTO = $this->translators->populateSchoolUserRoleDTOFromParmeter($roles->getSchoolRoleCode(), $profileCode, $roles->getProfileName(), $roles->getActive());
                        $schoolUserRoleDTO->setBaseProfileCode($profileCode);
                        $groupUserRoleModel = $this->translators->populateGroupUserRoleModelFromDto($schoolUserRoleDTO);
                        $groupUserRoleModel->setGroupUsers($profile);
                        $groupUserRoleModel->setRefCode($studentUuid);
                        $groupUserRoleModel->setProfileName($profileName);
                        $groupUserRoleModel->setRefProfileCode($studentBaseProfileCode);
                        $groupDAO->addGroupUserRoleDAO($groupUserRoleModel);
                      } else {
                        $schoolUserRoleDTO = $this->translators->populateSchoolUserRoleDTOFromParmeter($roles->getSchoolRoleCode(), $roles->getProfileCode(), $roles->getProfileName(), $roles->getActive());
                        $schoolUserRoleDTO->setBaseProfileCode($roles->getBaseProfileCode());
                        $groupUserRoleModel = $this->translators->populateGroupUserRoleModelFromDto($schoolUserRoleDTO);
                        $groupUserRoleModel->setGroupUsers($profile);
                        $groupUserRoleModel->setGroupUserId($roles->getGroupUserId());
                        $groupUserRoleModel->setRefCode($studentUuid);
                        $groupUserRoleModel->setProfileName($profileName);
                        $groupUserRoleModel->setId($roles->getId());
                        $groupUserRoleModel->setRefProfileCode($studentBaseProfileCode);
                        $groupDAO->updateProfileSync($groupUserRoleModel);
                      }
                      break;
                    }
                  }/* else {
                    //when no parent profileCode is found
                    throw new GroupException('No Parent profile code Exists For: ' . $studentUuid, 409);
                    } */
                }
              } else {
                //when no parent profileCode is found
                throw new GroupException('No Parent Exists For: ' . $studentUuid, 409);
              }
            }
          } else {
            //when no parent profile is found
            throw new GroupException('No Parent Exists For: ' . $studentUuid, 409);
          }
        }
        if ($NoProfileForParent) {
          throw new GroupException('No Parent Profile is created for Student Uuid:' . $studentUuid, 409);
        }
      } else {
        throw new GroupException('No Student Role Exists For Student Uuid:' . $studentUuid, 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getSearchGroups:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return true;
  }
  private function validateStudent($studentUuid, $groupId) {
    $profileCode = false;
    $groupDAO = new GroupDAO();
    $profiles = $groupDAO->getGroupIdDAO($groupId, $studentUuid);
    if (sizeof($profiles) > 0) {
      foreach ($profiles as $profile) {
        if (sizeof($profile->getGroupUserRoles()) > 0) {
          foreach ($profile->getGroupUserRoles() as $roles) {
            if ($roles->getSchoolRoleCode() == 'STU') {
              $profileCode = $roles;
            }
          }
        }
      }
    }
    return $profileCode;
  }
  private function getUserRelation($studentUuid, $relType) {
    $groupRequest = new GetUserRoleRequest();
    $groupRequest->setFromUuid($studentUuid);
    $groupRequest->setRelationCode($relType);
    //------------call service-----------------//
    $getUserRelationResponse = array();
    $userService = new UserService;
    $getUserRelationResponse = $userService->getUserParents($groupRequest);
    //print_r($getUserRelationResponse);die;
    return $getUserRelationResponse;
  }
  /**
   * Function updateProfile.
   * This function is used to update group user roles.
   * @author Naman Kumar Srivastava <naman@incaendo.com>
   * 
   * @param object $requestProfileSRO
   *  UpdateProfileObject object.
   *
   * @return object
   *  GroupUserRolesSRO object.
   */
  public function updateProfile($requestProfileSRO) {
    $this->validator->validateUpdateProfileRequest($requestProfileSRO);
    try {
      $groupDAO = new GroupDAO();
      if (!empty($requestProfileSRO->getGroupUserId())) {
        // Check for groupUserId existence.
        $groupUser = $groupDAO->getGroupUserById($requestProfileSRO->getGroupUserId());
        if (empty($groupUser)) {
          throw new GroupException("No record exist for groupUserId: " . $requestProfileSRO->getGroupUserId(), 409);
        }
      }
      if (!empty($requestProfileSRO->getSchoolRoleCode())) {
        // Check for schoolRoleCode existence.
        $schoolRole = $groupDAO->getSchoolRoleDetailsBySchoolRoleCode($requestProfileSRO->getSchoolRoleCode());
        if (empty($schoolRole)) {
          throw new GroupException("No record exist for schoolRoleCode: " . $requestProfileSRO->getSchoolRoleCode(), 409);
        }
      }
      // Check for profileCode existence.
      $groupUserRoles = $groupDAO->getProfileByCode(array($requestProfileSRO->getProfileCode()));
      if (empty($groupUserRoles)) {
        throw new GroupException("No record exist for profileCode: " . $requestProfileSRO->getProfileCode(), 409);
      }
      // sro to dto.
      $requestSchoolUserRoleDTO = $this->translators->populateSchoolUserRoleDTOFromProfileSRO($requestProfileSRO);
      // dto to sro.
      $requestSchoolUserRoleModel = $this->translators->populateSchoolUserRoleModelFromDTO($requestSchoolUserRoleDTO, $groupUserRoles[0]);
      // update SchoolUserRoleModel.
      $responseSchoolUserRoleModel = $groupDAO->updateProfileSync($requestSchoolUserRoleModel);
      // model to dto.
      $responseSchoolUserRoleDTO = $this->translators->populateSchoolUserRoleDTOFromModel($responseSchoolUserRoleModel);
      // dto to sro.
      return $this->translators->populateProfileSROFromSchoolUserRoleDTO($responseSchoolUserRoleDTO);
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:updateProfile:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
  }
  public function getUserDetailsByUuid($uuid) {
    $getUserByUuidRequest = new GetUserByUuidRequest;
    $getUserByUuidRequest->setUuid($uuid);
    $userService = new UserService;
    $UserDetails = $userService->getUserByUuid($getUserByUuidRequest);
    return $UserDetails;
  }
  
  public function getUserRelations($adultUuid = null, $minorUuid = null, $relationCode = null ) {
    $getUserRelationRequest = new GetUserRelationRequest;
    $getUserRelationRequest->setAdultUuid($adultUuid);    
    $userService = new UserService;
    $UserDetails = $userService->getUserRelations($getUserRelationRequest);
    return $UserDetails;
  }
  public function removeRoleForUser(GroupUserSRO $groupUserSRO, $addTeacherInSchoolGroup=false) {
    $this->validator->validateremoveRoleForUser($groupUserSRO);
    try {
      $status = false;
      $groupDaoObj = new GroupDAO();
      $groupCode = $groupUserSRO->getGroupCode();
      $groupDetails = $groupDaoObj->getGroupDetailsByGroupCode($groupCode);
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("This Group Code does not exist!", 409));
      }
      
      $groupsUsersDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
      $groupDetailDTO = $this->translators->populateDTOFromGroupModel($groupDetails[0]);
      $status = $this->removeUserRole($groupDetailDTO, $groupsUsersDTO, $addTeacherInSchoolGroup,false);
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:removeRoleForUser:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $status;
  }
  private function removeUserRole($groupDetailDTO, $groupsUsersDTO, $addTeacherInSchoolGroup=false, $syncSolr = true) {
    try {
      $status = false;
      $groupDaoObj = new GroupDAO();
      $getSchoolRole = $groupDaoObj->getSchoolRoleDetailsBySchoolRoleCode($groupsUsersDTO->getSchoolRoleCode());
      if (empty($getSchoolRole)) {
        throw(new \InvalidArgumentException("Invailid School Role Code:" . $groupsUsersDTO->getSchoolRoleCode(), 409));
      }
      
      $groupTypeID = $groupDetailDTO->getGroupTypeId();
      $schoolCode = $groupDetailDTO->getSchoolCode();
      $ayid = $groupDetailDTO->getAyid();
      $uuid = $groupsUsersDTO->getUuid();
      $groupId = $groupDetailDTO->getGroupId();
      
      //$groupUserDetailsWithRoles = $groupDaoObj->getGroupsUserDetails($groupTypeID, $schoolCode, $uuid);
      $groupUserDetailsWithRoles = $groupDaoObj->getGroupUserWithRoleDetails($groupTypeID, $schoolCode, $uuid, $ayid, $groupId);

      //print_r($groupUserDetailsWithRoles[0]); die();
      
      if (empty($groupUserDetailsWithRoles)) {
        throw(new \InvalidArgumentException("User does not exist in the group : ".$groupDetailDTO->getGroupCode()." with this SchoolCode:" . $schoolCode . " and Ayid:" . $ayid, 409));
      } elseif (empty($groupUserDetailsWithRoles[0]->getGroupIds()->getGroupCode())) {
        throw(new \InvalidArgumentException("Group does not exist with this uuid:" . $uuid, 409));
      } elseif (empty($groupUserDetailsWithRoles[0]->getGroupUserRoles()[0])) {
        throw(new \InvalidArgumentException("User role does not exist in the school with this uuid:" . $uuid . "SchoolCode: " . $schoolCode . " schoolRoleCode:" . $schoolUserRoleDTO->getSchoolRoleCode(), 409));
      }
      
      if($addTeacherInSchoolGroup){
        // start teacher case
        $teaRoleArr = array("SUBT", "SECT", "TEA");
        $param = array();
        $param["schoolCodeArr"][0] = $schoolCode;
        $param["ayidArr"][0] = $ayid;
        $param["schoolRoleCodeArr"] = $teaRoleArr;
        $param["uuidArr"][0] = $uuid;
        $param["groupUserActive"] = ACTIVE;
        $param["roleActive"] = ACTIVE;
        $userExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
        $modelArr = array();
        
        if(!empty($userExistInGroup)){
          $modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($userExistInGroup);
        }
        $teaCount = count($modelArr);
       
        $isTeacherAddInSchoolGroup = false;
        if($teaCount == "1"){
          foreach($modelArr as $TeaGroupDetails){
             if($TeaGroupDetails["groupCode"] == $groupUserDetailsWithRoles[0]->getGroupIds()->getGroupCode()){
               foreach($TeaGroupDetails["groupUsers"] as $TeaGroupUsers){
                 $TeaRoleCount = count($TeaGroupUsers["groupUserRole"]);
                 foreach($TeaGroupUsers["groupUserRole"] as $TeaGroupUserRole){
                   $TeaBaseProfileCode = $TeaGroupUserRole["baseProfileCode"];
                 }
               }
             }
           }
           if($TeaRoleCount == 1){
             $isTeacherAddInSchoolGroup = true;
           }
        }
      }
        
      $flag = 1;
      $params['isSingle'] = 0; // send on dow to inactive groupUser as per 0 and 1 condition.
      $params['key'] = ''; // this is use to send on dow  for to get index of role whic we inactive
      $userRoleCount = 0; // here we count the active roles of user.
      $userRoleArr = array();
      //----------calculating active roles for user here-------------------------------//
      foreach ($groupUserDetailsWithRoles[0]->getGroupUserRoles() as $roles) {
        if ($roles->getActive() == 1) {
          $userRoleArr[$roles->getSchoolRoleCode()] = 1;
         // $userRoleCount = $userRoleCount + 1;
        }
      }
      $userRoleCount = count($userRoleArr);
      //-------------------------------------ends here----------------------------//
      foreach ($groupUserDetailsWithRoles[0]->getGroupUserRoles() as $key => $groupUserRole) {
        if ($groupsUsersDTO->getSchoolRoleCode() == $groupUserRole->getSchoolRoleCode()) {
          $flag = 0;
          if ($userRoleCount == 1) {
            $groupUserDetailsWithRoles[0]->setIsActive(0);
            $params['isSingle'] = 1;
          }
          $groupUserRole->setActive(0);
          $params['key'] = $key;
         // break;
        }
      }
      if ($flag == 1) {
        throw(new \InvalidArgumentException("User does not exist in the school with this schoolRoleCode:" . $groupsUsersDTO->getSchoolRoleCode(), 409));
      }
      $groupModel=1;
      // $groupModel = $groupDaoObj->updateProfile($groupUserDetailsWithRoles[0], $params);
      // if ($groupModel) {
        $status = true;
        if ($syncSolr) {
          $this->solrInsertData($groupDaoObj, $groupUserDetailsWithRoles[0], $groupsUsersDTO->getSchoolRoleCode());
        }
      // }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:removeRoleForUser:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    
    if($isTeacherAddInSchoolGroup){
          $groupType = 3;
          //echo "--------";
          //echo $schoolCode;
         // echo "--------";
          //echo $ayid; die;
          $schoolGroupDetails = $groupDaoObj->getGroupsByGroupType($groupType, $schoolCode, $ayid);
          $schoolGroupCode = $schoolGroupDetails[0]->getGroupCode();
          $groupUserSRO = $this->translators->populateGroupsUserSROFromDTO($groupsUsersDTO);
          $groupUserSRO->setGroupCode($schoolGroupCode);
          $groupUserSRO->setSchoolRoleCode("TEA");
          $this->addUserToGroup($groupUserSRO, $TeaBaseProfileCode);
      }
    
    return $status;
  }
  public function syncProfiles($uuid, $groupCode, $schoolRoleCode, $actionedBy, $schoolCode) {
    $this->validator->validateSyncProfile($uuid, $groupCode, $schoolRoleCode, $actionedBy, $schoolCode);
    try {
      $groupDAO = new GroupDAO;
      $groupDetails = $groupDAO->getGroupIdByGroupCodeAndSchoolCode($groupCode, $schoolCode);
      if (count($groupDetails) > 0) {
        $groupId = $groupDetails[0]->getGroupId();
        $groupUserDetails = $groupDAO->getGroupIdDAO($groupId, $uuid);
        if (count($groupUserDetails) > 0) {
          if ($groupUserDetails[0]->getIsActive() == 0) {
            throw(new \InvalidArgumentException("Group User is Inactive", 409));
          } else {
            $groupUserId = $groupUserDetails[0]->getId();
            $profileDetails = $groupDAO->profileDetailsByRoleAndUserId($groupUserId, $schoolRoleCode);
            if (count($profileDetails) > 0) {
              $profileDetails[0]->setActive(0);
              $groupDAO->updateProfile($profileDetails[0]);
              $getAllProfileDetails = $groupDAO->profileDetailsByUserId($groupUserId);
              //  $getAllProfileDetails = $groupDAO->profileDetailsByRoleAndUserId($groupUserId);
              $inActive = 1;
              foreach ($getAllProfileDetails as $getAllProfileDetail) {
                if ($getAllProfileDetail->getActive() == 1) {
                  $inActive = 0;
                  break;
                }
              }
              if ($inActive) {
                $groupUserDetails[0]->setIsActive(0);
                $groupDAO->updateUserDAO($groupUserDetails[0]);
              }
            } else {
              throw(new \InvalidArgumentException("Profile details not found", 409));
            }
          }
        } else {
          throw(new \InvalidArgumentException("Group User details not found", 409));
        }
      } else {
        throw(new \InvalidArgumentException("Group details not found", 409));
      }
    } catch (\MySQLException $e) {
      throw new \GroupException($e->getMessage(), 500);
    }
    return true;
  }
  public function getSchoolRoleList() {
    try {
      $groupDaoObj = new GroupDAO();
      /* GroupModel */ $schoolRoleDetails = $groupDaoObj->getSchoolRoleDetails();
      $cnt = count($schoolRoleDetails);
      if (!empty($schoolRoleDetails) && $cnt > 0) {
        $profileArray = array();
        foreach ($schoolRoleDetails as $schoolRoleDetail) {
          $schoolRoleDTO = $this->translators->populateSchoolRoleDTOFromModel($schoolRoleDetail);
          $SchoolRoleSROArr[] = $this->translators->populateSchoolRoleSROFromSchoolRoleDTO($schoolRoleDTO);
        }
      } else {
        throw new GroupException('Record not found with getSchoolRoleList request.', 409);
      }
    } catch (SqlException $e) {
      $this->logger->error("App\Component\GroupComponent:getSchoolRoleList:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 409);
    }
    return $SchoolRoleSROArr;
  }
  public function getGroupUuid($groupCode, $uuid) {
    $this->validator->validateGroupUuid($groupCode, $uuid);
    try {
      $groupDaoObj = new GroupDAO();
      $groupId = $groupDaoObj->getGroupIdByGroupCode($groupCode);
      //getexistUserInGroup
      /* GroupModel */ $groupUserDetails = $groupDaoObj->getexistUserInGroup($groupId, $uuid);
      $cnt = count($groupUserDetails);
      if (!empty($groupUserDetails) && $cnt > 0) {
        foreach ($groupUserDetails as $groupUserDetail) {
          $groupType = $groupDaoObj->getGroupTypeByGroupTypeId($groupUserDetail->getGroupIds()->getGroupTypeId());
          $groupUserDetailDTO = $this->translators->populateGroupsUsersMappingDTOFromModel($groupUserDetail);
          $groupUserDetailSRO = $this->translators->populateGroupUuidSROFromDTO($groupUserDetailDTO);
          $groupUserDetailSRO->setGroupTypeCode($groupType[0]->getGroupTypeCode());
        }
      } else {
        throw new GroupException('Your profile might be deactivated. Please try login in again.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getUsersMappingByGroupCode:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $groupUserDetailSRO;
  }
  /**
   * Function getGroupUserProfileFromDB.
   * This function is used get Search Groups. 
   * @author Gaurav Sengar <gaurav@incaendo.com>
   */
  public function getGroupUserProfileFromDB(\App\HTTP\GetProfileListForUser $searchGroupsSRO, $pageNum, $pageSize) {
    $this->validator->validateGetGroupUserProfileFromDB($searchGroupsSRO);
    $this->validator->validatePageNumAndPageSize($pageNum, $pageSize);
    try {
      $searchDTO = $this->translators->populateUserListDTOFromSRO($searchGroupsSRO);
      $condition = $this->getPageLimit($pageNum, $pageSize);
      $param = array();
      $param["groupCodeArr"] = explode("|", $searchDTO->getGroupCode());
      $param["schoolCodeArr"] = explode("|", $searchDTO->getSchoolCode());
      $param["ayidArr"] = explode("|", $searchDTO->getAyid());
      $param["groupTypeCodeArr"] = explode("|", $searchDTO->getGroupTypeCode());
      $param["roleActive"] = $searchDTO->getRoleActive();
      $param["groupActive"] = $searchDTO->getGroupActive();
      $param["groupUserActive"] = $searchDTO->getGroupUserActive();
      $groupDaoObj = new GroupDAO();
      if (!empty($param["groupTypeCodeArr"][0])) {
        $groupTypeModel = $groupDaoObj->getGroupTypeByGroupTypeCode($param["groupTypeCodeArr"]);
        if (is_array($groupTypeModel) && !empty($groupTypeModel)) {
          foreach ($groupTypeModel as $groupType) {
            $param["groupTypeIdArr"][] = $groupType->getId();
          }
        }
      }
      $param["uuidArr"] = explode("|", $searchDTO->getUuid());
      $param["profileCodeArr"] = explode("|", $searchDTO->getProfileCode());
      $param["schoolRoleCodeArr"] = explode("|", $searchDTO->getSchoolRoleCode());
      $param["refCodeArr"] = explode("|", $searchDTO->getRefCode());
      $param["baseProfileArr"] = explode("|", $searchDTO->getBaseProfile());
      /* GroupModel */ $groupDetails = $groupDaoObj->getGroupUserProfileFromDB($param);
      if (empty($groupDetails)) {
        //throw new GroupException('No Record found with getGroupUserProfileFromDB request.', 409);
        throw(new \InvalidArgumentException("No Record found with getGroupUserProfileFromDB request.", 409));
      }
      if (!empty($param["profileCodeArr"][0]) || !empty($param["schoolRoleCodeArr"][0]) || !empty($param["refCodeArr"][0]) || !empty($param["baseProfileArr"][0])) {
        $modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($groupDetails);
      }
      if (!empty($param["uuidArr"][0]) && empty($param["profileCodeArr"][0]) && empty($param["schoolRoleCodeArr"][0]) && empty($param["refCodeArr"][0]) && empty($param["baseProfileArr"][0])) {
        $modelArr = $this->translators->populateGroupDetailsArrFromModel($groupDetails);
      }
      if ((!empty($param["groupCodeArr"][0]) || !empty($param["schoolCodeArr"][0]) || !empty($param["ayidArr"][0]) || !empty($param["groupTypeIdArr"][0])) && empty($param["uuidArr"][0]) && empty($param["profileCodeArr"][0]) && empty($param["schoolRoleCodeArr"][0]) && empty($param["refCodeArr"][0]) && empty($param["baseProfileArr"][0])) {
        $modelArr = $this->translators->populateGroupUserDetailsArrFromModel($groupDetails);
      }
      $cnt = count($modelArr);
      $totalPages = ceil($cnt / $pageSize);
      if ($pageNum > $totalPages) {
        throw new GroupException('Records are not present for this Page Number.', 409);
      }
      if ($pageSize > MAX_LIMIT) {
        throw new GroupException('Please set lower Page Size Limit.', 409);
      }
      $groupDetailsSRO = array();
      if (!empty($modelArr)) {
        foreach ($modelArr as $key => $groupModel) {
          $groupDetailsDTO = $this->translators->populateGroupDetailsFromModel($groupModel);
          $groupDetailsSRO[$key] = $this->translators->populateGroupDetailSROFromDTO($groupDetailsDTO);
        }
      } else {
        throw new GroupException('Record not found with getGroupUserProfileFromDB request.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getGroupUserProfileFromDB:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $groupDetailsSRO;
  }
  public function getGroupsBySchoolCode($ayid, $schoolCode) {
    $this->validator->validateGetGroupsByGroupType($schoolCode, $ayid);
    try {
      $groupDAO = new GroupDAO();
      /* GroupModel */ $groupsBySchoolCode = $groupDAO->getGroupsBySchoolCode($ayid, $schoolCode);
      $cnt = count($groupsBySchoolCode);
      if ($cnt > 0) {
        foreach ($groupsBySchoolCode as $groupBySchoolCode) {
          /* GroupDTO */ $groupsBySchoolCodeDetailDTO = $this->translators->populateDTOFromGroupModel($groupBySchoolCode);
          $groupsBySchoolCodeDetailSRO[] = $this->translators->populateSROFromDTO($groupsBySchoolCodeDetailDTO);
        }
      } else {
        throw new GroupException('No Record found with getGroupsBySchoolCode request.', 404);
      }
    } catch (\SqlException $e) {
      $this->logger->error("App\Component\GroupComponent:getGroupsBySchoolCode:" . $e->getMessage(), 503);
      throw new GroupException($e->getMessage());
    }
    return $groupsBySchoolCodeDetailSRO;
  }
  
  function changeGroupForUser(GroupUserSRO $groupUserSRO) {
    $this->logger->debug("[GroupController:changeGroupForUser component call");
    $this->validator->validateChangeGroupForUser($groupUserSRO);
    $groupDaoObj = new GroupDAO();
    $groupDaoObj->beginTransaction();
    $this->logger->debug("[GroupController:changeGroupForUser component call : Transaction begin");
    try {
      $activeDate = date('Y-m-d');
      $deactiveDate = date('Y-m-d', strtotime('+1 years'));
      $groupUserSRO->setActiveDate($activeDate);
      $groupUserSRO->setDeactiveDate($deactiveDate);
      
      $oldGroupCodeCnt = count($groupUserSRO->getOldGroupCode());
      
      $newGroupCodeCnt = count($groupUserSRO->getNewGroupCode());
      
      $roleArr = array("STU", "PAR");
      if(in_array($groupUserSRO->getSchoolRoleCode(), $roleArr)){
        if ($oldGroupCodeCnt > 0) {
          if($newGroupCodeCnt<=0){
            throw(new \InvalidArgumentException("newGroupCode can not be empty for schoolRoleCode : ".$groupUserSRO->getSchoolRoleCode(), 409));
          }
        }
      }
      
      $teacherRoleArr = array("TEA", "SUBT", "SECT");
      if ($oldGroupCodeCnt > 0) {
        foreach ($groupUserSRO->getOldGroupCode() as $oldGroupCode) {
          $addTeacherInSchoolGroup = false;
          if (!empty($oldGroupCode)) {
            $groupUserSRO->setGroupCode($oldGroupCode); //for remove user from old group
            if(in_array($groupUserSRO->getSchoolRoleCode(), $teacherRoleArr)){
              $addTeacherInSchoolGroup = true;
            }
            $this->logger->debug("[GroupController:changeGroupForUser:removeRoleForUser API Start at : " . json_encode(date("Y-m-d h:i:s")) . "]");
            $this->logger->debug("[GroupController:changeGroupForUser:removeRoleForUser API Request " . $this->serialize($groupUserSRO));
            $removeRoleForUserResult = $this->removeRoleForUser($groupUserSRO,$addTeacherInSchoolGroup);
            $this->logger->debug("[GroupController:changeGroupForUser] Group removeRoleForUser API Responce : " . json_encode($removeRoleForUserResult));
            $this->logger->debug("[GroupController:changeGroupForUser:removeRoleForUser API End at : " . json_encode(date("Y-m-d h:i:s")) . "]");
          } else {
            throw(new \InvalidArgumentException("Invailid oldGroupCode.", 409));
          }
        }
      }
      
      if ($newGroupCodeCnt > 0) {
        foreach ($groupUserSRO->getNewGroupCode() as $newGroupCode) {
          if (!empty($newGroupCode)) {
            $groupUserSRO->setGroupCode($newGroupCode); //for add user in new group
            $this->logger->debug("[GroupController:changeGroupForUser:addUserToGroup API Start at : " . json_encode(date("Y-m-d h:i:s")) . "]");
            $this->logger->debug("[GroupController:changeGroupForUser:addUserToGroup API Request " . $this->serialize($groupUserSRO));
            $addUserToGroupReturn = $this->addUserToGroup($groupUserSRO);
            $this->logger->debug("[GroupController:changeGroupForUser] Group addUserToGroup API return : " . json_encode($addUserToGroupReturn));
            $this->logger->debug("[GroupController:changeGroupForUser:addUserToGroup API End at : " . json_encode(date("Y-m-d h:i:s")) . "]");
          } else {
            throw(new \InvalidArgumentException("Invailid newGroupCode.", 409));
          }
        }
      }
      
      $groupDaoObj->commitTransaction();
      $this->logger->debug("[GroupController:changeGroupForUser component call : Transaction end");
      return true;
    } catch (\Exception $e) {
      $groupDaoObj->rollBackTransaction();
      $this->logger->debug("[GroupController:changeGroupForUser component call : Transaction end");
      throw new GroupException($e->getMessage(),$e->getCode());
    }
  }
  function joinUserToSchool($addTeacherToMultiGroupRequest) {
    $this->validator->validateAddTeacherToMultiGroup($addTeacherToMultiGroupRequest);
    $schoolCode = $addTeacherToMultiGroupRequest->getSchoolCode();
    $ayid = $addTeacherToMultiGroupRequest->getAyid();
    $uuid = $addTeacherToMultiGroupRequest->getUuid();
    $schoolRoleCode = $addTeacherToMultiGroupRequest->getSchoolRoleCode();
    $actionedBy = $addTeacherToMultiGroupRequest->getActionedBy();
    $activeDate = date('Y-m-d');
    $deactiveDate = date('Y-m-d', strtotime('+1 years'));
    $groupDAO = new GroupDAO();
    $groupDAO->beginTransaction();
    try {
      /* GroupModel */ $groupsBySchoolCode = $groupDAO->getGroupsBySchoolCode($ayid, $schoolCode);
      $cnt = count($groupsBySchoolCode);
      if ($cnt > 0) {
        foreach ($groupsBySchoolCode as $groupBySchoolCode) {
          $groupModelArr[$groupBySchoolCode->getGroupCode()] = $groupBySchoolCode;
          $groupsBySchoolCodeDetailDTO = $this->translators->populateDTOFromGroupModel($groupBySchoolCode);
          $groupsBySchoolCodeDetail = $groupsBySchoolCodeDetailSRO[] = $this->translators->populateSROFromDTO($groupsBySchoolCodeDetailDTO);
          //if ($groupsBySchoolCodeDetail->getGroupTypeCode() == "SCHG" || $groupsBySchoolCodeDetail->getGroupTypeCode() == "ATG") {
          if ($groupsBySchoolCodeDetail->getGroupTypeCode() == "SCHG") {
            $schoolAndAtgGroupArr[$groupsBySchoolCodeDetail->getGroupTypeCode()] = $groupsBySchoolCodeDetail->getGroupCode();
          }
          $groupCodeWithGroupTypeArr[$groupsBySchoolCodeDetail->getGroupCode()] = $groupsBySchoolCodeDetail->getGroupTypeCode();
        }
      } else {
        throw new GroupException("No Record found with schoolCode:" . $schoolCode . " and Ayid:" . $ayid, 404);
      }
      //$tracher_role_arr = array("SCHG" => "TEA", "SUBG" => "SUBT", "SECG" => "SECT", "SCG" => "TEA", "ATG" => "TEA");
      $tracher_role_arr = array("SCHG" => "TEA", "SUBG" => "SUBT", "SECG" => "SECT", "SCG" => "TEA");
     // $groupTypeForUserArr = array("TEA" => array("SCHG", "ATG"), "STU" => array("SCHG"), "PRI" => array("SCHG"), "SAD" => array("SCHG"), "STF" => array("SCHG"), "PAR" => array("SCHG"), "SECT" => array("SECG"), "SUBT" => array("SUBG"));
      $groupTypeForUserArr = array("TEA" => array("SCHG"), "STU" => array("SCHG"), "PRI" => array("SCHG"), "SAD" => array("SCHG"), "STF" => array("SCHG"), "PAR" => array("SCHG"), "SECT" => array("SECG"), "SUBT" => array("SUBG"));
      $groupTypeArr = $groupTypeForUserArr[$schoolRoleCode];
      if (empty($groupTypeArr)) {
        throw new GroupException("User can not add with GroupCode:" . $groupCode . " and groupType:" . $groupCodeWithGroupTypeArr[$groupCode], 404);
      }
      foreach ($groupTypeArr as $groupTypeCode) {
        //$schoolRoleCode = $role_arr[$groupTypeCode];
        $groupUserArr["uuid"] = $uuid;
        $groupUserArr["actionedBy"] = $actionedBy;
        $groupUserArr["groupCode"] = $schoolAndAtgGroupArr[$groupTypeCode];
        $groupUserArr["activeDate"] = $activeDate;
        $groupUserArr["deactiveDate"] = $deactiveDate;
        $groupUserArr["schoolRoleCode"] = $schoolRoleCode;
        $groupUserSRO = $this->translators->populateGroupsUserSROFromGroupArr($groupUserArr);
        $groupModel = $groupModelArr[$groupUserArr["groupCode"]];
        $groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
        $groupUserDetails = $this->addUser($groupModel, $groupUserDTO);
      }
      if (!empty($addTeacherToMultiGroupRequest->getGroupCode())) {
        $groupCodeArr = array_keys($groupCodeWithGroupTypeArr);
        foreach ($addTeacherToMultiGroupRequest->getGroupCode() as $groupCode) {
          if (in_array($groupCode, $groupCodeArr)) {
            if (!in_array($groupCodeWithGroupTypeArr[$groupCode], $groupTypeArr)) {
              if ($schoolRoleCode == "TEA") {
                $teacherRoleCode = $tracher_role_arr[$groupCodeWithGroupTypeArr[$groupCode]];
                if (empty($teacherRoleCode)) {
                  throw new GroupException("User can not add with GroupCode:" . $groupCode . " and groupType:" . $groupCodeWithGroupTypeArr[$groupCode], 404);
                }
                $groupUserArr["schoolRoleCode"] = $teacherRoleCode;
              }else{
                $groupUserArr["schoolRoleCode"] = $schoolRoleCode;
              }
              
              $groupUserArr["uuid"] = $uuid;
              $groupUserArr["actionedBy"] = $actionedBy;
              $groupUserArr["groupCode"] = $groupCode;
              $groupUserArr["activeDate"] = $activeDate;
              $groupUserArr["deactiveDate"] = $deactiveDate;
              
              $groupUserSRO = $this->translators->populateGroupsUserSROFromGroupArr($groupUserArr);
              $groupModel = $groupModelArr[$groupUserArr["groupCode"]];
              $groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
              $groupUserDetails = $this->addUser($groupModel, $groupUserDTO);
            }
          }
        }
      }
      $groupDAO->commitTransaction();
      return true;
    } catch (\Exception $e) {
      $groupDAO->rollBackTransaction();
      throw new GroupException($e->getMessage(), $e->getCode());
    }
  }
  public function bulkAddUserRoleToGroup($bulkAddUserRoleToGroupSRO) {
    $this->validator->validateBulkAddUserRoleToGroup($bulkAddUserRoleToGroupSRO);
    try {
      $schoolCode = $bulkAddUserRoleToGroupSRO->getgroup()->getSchoolCode();
      $ayid = $bulkAddUserRoleToGroupSRO->getgroup()->getAyid();
      $groupCode = $bulkAddUserRoleToGroupSRO->getgroup()->getGroupCode();
      $actionedBy = $bulkAddUserRoleToGroupSRO->getgroup()->getActionedBy();
      $activeDate = date('Y-m-d');
      $deactiveDate = date('Y-m-d', strtotime('+1 years'));
      $groupUserDetailsSRO = $bulkAddUserRoleToGroupSRO->getgroup()->getGroupUserRole();
      $groupDAO = new GroupDAO();
      $groupDetails = $groupDAO->getGroupDetailsByGroupCode($groupCode, $schoolCode, $ayid);
      if (count($groupDetails) < 0) {
        throw new GroupException("No Record found for this group in the School", 404);
      }
      $groupUsersDetail = $groupDAO->getUsersByGroupCode($groupCode);
      $groupDAO->beginTransaction();
      foreach ($groupUserDetailsSRO as $groupUserDetailSRO) {
        if (!(in_array($groupUserDetailSRO->getUuid(), $groupUsersDetail))) {
          $groupUserSRO = $this->translators->populateGroupUserSROFromGroupUserDetailSRO($groupUserDetailSRO, $groupCode, $actionedBy, $activeDate, $deactiveDate);
          $groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
          //$this->addUserToGroup($groupUserSRO);
          $this->addUser($groupDetails[0], $groupUserDTO);
        }
      }
      $groupDAO->commitTransaction();
      return true;
    } catch (\Exception $e) {
      $groupDAO->rollBackTransaction();
      throw new GroupException($e->getMessage(), $e->getCode());
    }
  }
  function exitUserFromSchool(GroupUserSRO $groupUserSRO) {
    $this->validator->validateExitUserFromSchool($groupUserSRO);
    $groupsUsersDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
    $groupDAO = new GroupDAO();
    $param["uuidArr"][0] = $groupsUsersDTO->getUuid();
    $param["schoolCodeArr"][0] = $groupsUsersDTO->getSchoolCode();
    $param["ayidArr"][0] = $groupsUsersDTO->getAyid();
    if(!empty($groupsUsersDTO->getProfileCode())) {
        $param["baseProfile"][0] = $groupsUsersDTO->getProfileCode();
    }
    $schoolRoleCode = $groupsUsersDTO->getSchoolRoleCode();
    if ($schoolRoleCode == "TEA") {
      $param["schoolRoleCodeArr"] = array("TEA", "SECT", "SUBT");
    } else {
      $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
    }
    $param["groupUserActive"] = ACTIVE;
    $param["roleActive"] = ACTIVE;
    
    /* @Fliplearn 3.0 : ATG group related changes
     * $removeTeaFromGroup = array("TEA" => array("SCHG" => "TEA", "SECG" => "SECT", "SUBG" => "SUBT", "ATG" => "TEA", "SCG" => "TEA"));
     */
    
    //$removeTeaFromGroup = array("TEA" => array("SCHG" => "TEA", "SECG" => "SECT", "SUBG" => "SUBT", "SCG" => "TEA"));
     echo "<pre>"; print_r($param); //die();


    $groupDetails = $groupDAO->getGroupUserRoleDetailsByParam($param);
    //echo "<pre>"; print_r($groupDetails); die();
    $modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($groupDetails);
    //print_r($modelArr); die("jjjjjj");
    $groupDetailsSRO = array();
    if (!empty($modelArr)) {
      foreach ($modelArr as $key => $groupModel) {
        $groupDetailsDTO[$key] = $this->translators->populateGroupDetailsFromModel($groupModel);
      }
    } else {
      throw new GroupException('Record not found with exitUserFromSchool request.', 409);
    }
    $groupDAO->beginTransaction();
    try {
      foreach ($groupDetailsDTO as $groupDetail) {
        /*if ($schoolRoleCode == "TEA" && ($groupDetail->getGroupTypeCode()!= "ATG") ) {
          $removeTeaRole = $removeTeaFromGroup[$schoolRoleCode][$groupDetail->getGroupTypeCode()];
          $groupsUsersDTO->setSchoolRoleCode($removeTeaRole);
        }*/
        if(!empty($groupDetail->getGroupUsers())){
          foreach ($groupDetail->getGroupUsers() as $groupUserDetail) {
            if(!empty($groupUserDetail->getGroupUserRoles())){
              foreach ($groupUserDetail->getGroupUserRoles() as $groupUserRoleDetail) {
                $groupsUsersDTO->setGroupCode($groupDetail->getGroupCode());
                $groupsUsersDTO->setSchoolRoleCode($groupUserRoleDetail->getSchoolRoleCode());
                $this->removeUserRole($groupDetail, $groupsUsersDTO, false, true);
              }
            }
          }
        } 
      }
      $groupDAO->commitTransaction();
      return true;
    } catch (\Exception $e) {
      print_r($e->getTraceAsString());
      die($e->getMessage());
      $groupDAO->rollBackTransaction();
      throw new GroupException($e->getMessage(), $e->getCode());
    }
  }
  /**
   * Function addUserToGroup.
   * This function is used to add Users.
   * @author Gaurav Sengar <gaurav@incanedo.com>
   */
  public function addUserToGroup(GroupUserSRO $groupUserSRO, $TeaBaseProfileCode = null) {
    $groupUserDetails = '';
    $this->validator->validateAddGroupUser($groupUserSRO);
    try {
      $groupDaoObj = new GroupDAO();
      $groupDetails = $groupDaoObj->getGroupDetailsByGroupCode($groupUserSRO->getGroupCode());
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("This Group Code does not exist!", 409));
      }
      if ($groupDetails[0]->getIsActive() == 0) {
        throw(new \InvalidArgumentException("This group is not active!", 409));
      }
      
      $groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
      $groupUserDetails = $this->addUser($groupDetails[0], $groupUserDTO, $TeaBaseProfileCode);
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:addUserToGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $groupUserDetails;
  }
  
  private function addUser($groupDetails, $groupUserDTO, $TeaBaseProfileCode = null) {
    $groupUserDetails = '';
    try {
      $groupDetailDTO = $this->translators->populateDTOFromGroupModel($groupDetails);
      $schoolRoleCode = $groupUserDTO->getSchoolRoleCode();
      if(!empty($schoolRoleCode)){
        $groupUserDTO->setSchoolRoleCode(strtoupper($schoolRoleCode));
      }
      
      $uuid = $groupUserDTO->getUuid();
      $groupDaoObj = new GroupDAO();
      $groupTypeId = $groupDetailDTO->getGroupTypeId();
      $groupTypeCodeDetails = $groupDaoObj->getGroupTypeByGroupTypeId($groupTypeId);
      if (empty($groupTypeCodeDetails)) {
        throw(new \InvalidArgumentException("Group Type Code Details does not exist", 409));
      }
      $groupTypeCode = $groupTypeCodeDetails[0]->getGroupTypeCode();
      
      $role_arr = array("SCHG" => array("PRI", "STU", "SAD", "STF", "PAR", "TEA"), "SUBG" => array(), "SECG" => array("SECT", "STU", "PAR", "SUBT"), "SCG" => array("SAD", "PRI", "SUBT", "SECT", "TEA", "STU"), "ATG" => array(), "AUG" => array("SPA"), "CUG" => array());
      $school_role_arr = array("PRI" => "School Principal", "SECT" => "Class Teacher", "STU" => "Student", "SAD" => "School Admin", "STF" => "School Staff", "SUBT" => "Subject Teacher", "PAR" => "Parent", "TEA" => "Teacher", "SPA" => "Application User");
      if (empty($role_arr[$groupTypeCode])) {
        throw(new \InvalidArgumentException("User can not add in this group : " . $groupTypeCode, 409));
      }
      $schoolRoleCode = $groupUserDTO->getSchoolRoleCode();
      if (!in_array($schoolRoleCode, $role_arr[$groupTypeCode])) {
        throw(new \InvalidArgumentException($school_role_arr[$schoolRoleCode] . " can not add in this group with schoolRoleCode:".$schoolRoleCode." and groupTypeCode:" . $groupTypeCode, 409));
      }
      
      $groupId = $groupDetailDTO->getGroupId();
      
      $schoolRoleCode = $groupUserDTO->getSchoolRoleCode();
      for ($i=0;$i<5;$i++) {
        $profileCode = $this->util->generateCode();
        try {
          $groupUserRoleResponse = $groupDaoObj->getGroupUserRoleByProfileCode($profileCode);
          if (empty($groupUserRoleResponse)) {
            break;
          }
          if($i == 4) {
            throw(new \InvalidArgumentException("Could not generate unique prfile code", 409));
          }
        } catch (\MySQLException $e) {
          $this->logger->error("[GroupComponent:addUserToGroup:MySQLException:Message:" . $e->getMessage());
        }
      }
      $schoolRoleDetails = $groupDaoObj->getSchoolRoleDetailsBySchoolRoleCode($groupUserDTO->getSchoolRoleCode());
      $schoolRoleDescription = $schoolRoleDetails[0]->getDescription();
      
      // start teacher case
      $teaRoleArr = array("SUBT", "SECT", "TEA");
      if (in_array($schoolRoleCode, $teaRoleArr)) {
        $param = array();
        if($groupTypeCode == SCHOOL && $schoolRoleCode == "TEA"){
          $param["schoolCodeArr"][0] = $groupDetailDTO->getSchoolCode();
          $param["ayidArr"][0] = $groupDetailDTO->getAyid();
          $param["schoolRoleCodeArr"] = $teaRoleArr;
          $param["uuidArr"][0] = $uuid;
        }else{
          $param["groupCodeArr"][0] = $groupUserDTO->getGroupCode();
          $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
        }
        $param["groupUserActive"] = ACTIVE;
        $param["roleActive"] = ACTIVE;
        $userExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
        $modelArr = array();
        if(!empty($userExistInGroup)){
          $modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($userExistInGroup);
        }
        $teaCount = count($modelArr);
        
        if($groupTypeCode == SCHOOL && $schoolRoleCode == "TEA"){
          if($teaCount > 0){
              throw(new \InvalidArgumentException("Teacher already exist in the school", 409));
          }
        }
        
        /*if($schoolRoleCode == "SUBT"){
          if(!empty($modelArr)){
            foreach ($modelArr as $gd){
              $subtCnt = count($gd["groupUsers"]);
            }
            if($subtCnt >= 5){
              throw(new \InvalidArgumentException("There can not be more than five Subject teacher in the group:" . $groupUserDTO->getGroupCode() . " and schoolRoleCode:" . $schoolRoleCode, 409));
            }
          }
        }*/
        if($groupTypeCode != SCG) {
            if($schoolRoleCode == "SECT"){
              if($teaCount >= 1){
                   throw(new \InvalidArgumentException("There can not be more than one Section teacher in the group:" . $groupUserDTO->getGroupCode() . " with schoolRoleCode:" . $schoolRoleCode, 409));
              }
            }
        }
      } // end teacher case
      
      // start student case
      if ($schoolRoleCode == "STU") {
          $param["schoolCodeArr"][0] = $groupDetailDTO->getSchoolCode();
          $param["ayidArr"][0] = $groupDetailDTO->getAyid();
          $param["groupTypeIdArr"][0] = 1; //$groupTypeId; // use groupTypeId 1 for section group
          $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
          $param["uuidArr"][0] = $uuid;
          //$param["roleActive"] = ACTIVE;
          //$param["groupUserActive"] = ACTIVE;
          $stuExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
          $stuExistInGroupArr = array();
          if(!empty($stuExistInGroup)){
            $stuExistInGroupArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($stuExistInGroup);
          }
          
          $activeStuCount = 0;
          $inactiveStuCount = 0;
          if(!empty($stuExistInGroupArr)){ 
            foreach($stuExistInGroupArr as $groupDetailsArr){
              if($groupDetailsArr["isActive"] == ACTIVE){
                if(!empty($groupDetailsArr["groupUsers"])){
                  foreach($groupDetailsArr["groupUsers"] as $userDetailsArr){
                    if($uuid == $userDetailsArr["uuid"]){
                      if(!empty($userDetailsArr)){
                        foreach($userDetailsArr["groupUserRole"] as $roleDetailsArr){
                          if($roleDetailsArr["active"] ==  ACTIVE){
                              $activeStuCount = $activeStuCount +1;
                          }else{
                            $inactiveStuCount = $inactiveStuCount+1;
                          }
                        }
                      }
                    }
                  }
                }
              } 
            }
          }
        if($groupTypeCode != SCG) {  
          if ($activeStuCount>0) {
            throw(new \InvalidArgumentException("User already exist in the section group with schoolRoleCode:" . $schoolRoleCode, 409));
          }
        }
          if ($inactiveStuCount > STUDENT_SECTION_LIMIT) {
            throw(new \InvalidArgumentException("Student can't change more then ".STUDENT_SECTION_LIMIT." section with schoolRoleCode:" . $schoolRoleCode, 409));
          }
          
      } // end student case
      
      // start parent case
      if ($schoolRoleCode == "PAR") {
          $param["schoolCodeArr"][0] = $groupDetailDTO->getSchoolCode();
          $param["ayidArr"][0] = $groupDetailDTO->getAyid();
          $param["groupTypeIdArr"][0] = 1; // use groupTypeId 1 for section group
          $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
          $param["uuidArr"][0] = $uuid;
          $param["roleActive"] = ACTIVE;
          $param["groupUserActive"] = ACTIVE;
          $parExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
          
          $parExistInGroupArr = array();
          if(!empty($parExistInGroup)){
            $parExistInGroupArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($parExistInGroup);
          }
          
          $parCnt = count($parExistInGroupArr);
          
          /*if ($parCnt > 0 && $groupTypeCode ==  SCHOOL) {
            throw(new \InvalidArgumentException("Parent already exist in section group with schoolRoleCode:" . $schoolRoleCode, 409));
          }*/
          
          if ($parCnt >= 10 && $groupTypeCode ==  SECTION) {
            throw(new \InvalidArgumentException("Parent already exist in 10 section group with schoolRoleCode:" . $schoolRoleCode, 409));
          }
          
          if ($groupTypeCode ==  SECTION) {
            $userRelationsSMO = $this->getUserRelations($uuid);
            $minorUuidArr = array();
            if(!empty($userRelationsSMO->getRelations()[0]))
            {
              foreach($userRelationsSMO->getRelations() as $relations){
                $minorUuidArr[] = $relations->getMinorUuid();
              }
            }
            
            if(!empty($minorUuidArr)){
              $param = array();
              $param["groupCodeArr"][0] = $groupUserDTO->getGroupCode();
              $param["schoolRoleCodeArr"][0] = "STU";
              $param["uuidArr"][0] = $minorUuidArr;
              $param["roleActive"] = ACTIVE;
              $param["groupUserActive"] = ACTIVE;
              $stuExistInGroupOfParent = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
              
              $stuExistInGroupOfParentArr = array();
              if(!empty($stuExistInGroupOfParent)){
                $stuExistInGroupOfParentArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($stuExistInGroupOfParent);
              }
          
              if (!empty($stuExistInGroupOfParentArr)) {
                throw(new \InvalidArgumentException("Minor user already exist for this parent with groupCode:" . $groupUserDTO->getGroupCode(), 409));
              }
            }
          }
      }// end parent case
      
      
      $UserDetails = $this->getUserDetailsByUuid($uuid);
      if (empty($UserDetails->getUser()[0])) {
        throw(new \InvalidArgumentException($UserDetails->getError()["internalMessage"], 409));
      }
      
      //add adult/minor case
      $userTypWithRoleArr = array("M"=>array("STU"), "A"=>array("PRI","SECT", "SAD", "STF", "SUBT", "PAR", "TEA", "SPA"));
      $userTypArr = array("M"=>"Minor","A"=>"Adult");
      if (!empty($UserDetails->getUser()[0]->getUserType())) {
        $userType = $UserDetails->getUser()[0]->getUserType();
        $UserRoleArr = $userTypWithRoleArr[$userType];
        if (!in_array($schoolRoleCode, $UserRoleArr)) {
          throw(new \InvalidArgumentException($school_role_arr[$schoolRoleCode]." can not be ". $userTypArr[$userType], 409));
        }
      }
      
      if (!empty($UserDetails->getUser()[0]->getFirstName())) {
        $profileName = $UserDetails->getUser()[0]->getFirstName() . " ";
      }
      if (!empty($UserDetails->getUser()[0]->getMiddleName())) {
        $profileName .= $UserDetails->getUser()[0]->getMiddleName() . " ";
      }
      if (!empty($UserDetails->getUser()[0]->getLastName())) {
        $profileName .= $UserDetails->getUser()[0]->getLastName() . " ";
      }
      $profileName .= $schoolRoleDescription;
      if ($schoolRoleCode == SPA) {
        $profileName = "Fliplearn Super Admin";
      }
      
      
      $active = ACTIVE;
      $schoolUserRoleDTO = $this->translators->populateSchoolUserRoleDTOFromParmeter($schoolRoleCode, $profileCode, $profileName, $active);
      
      // start get user exist in school
      $param = array();
      $param["schoolCodeArr"][0] = $groupDetailDTO->getSchoolCode();
      $param["ayidArr"][0] = $groupDetailDTO->getAyid();
      $param["uuidArr"][0] = $groupUserDTO->getUuid();
      if(in_array($schoolRoleCode, $teaRoleArr)){
        $param["schoolRoleCodeArr"] = $teaRoleArr;
      }else{
        $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
      }
      
      //$param["groupUserActive"] = ACTIVE;
      //$param["roleActive"] = ACTIVE;
      $userExistInSchool = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
      
      if(!empty($userExistInSchool)){
        $userExistInSchoolArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($userExistInSchool);
        foreach($userExistInSchoolArr as $groupDetailsArr){
          if(!empty($groupDetailsArr["groupUsers"])){
            foreach($groupDetailsArr["groupUsers"] as $userDetailsArr){
              if($groupUserDTO->getUuid() == $userDetailsArr["uuid"]){
                if(!empty($userDetailsArr)){
                  foreach($userDetailsArr["groupUserRole"] as $roleDetailsArr){
                    //if($roleDetailsArr["schoolRoleCode"] == $schoolRoleCode){
                      if(in_array($roleDetailsArr["schoolRoleCode"] , $param["schoolRoleCodeArr"])){
                      /*$schoolUserRoleDTO->setBaseProfileCode($roleDetailsArr["baseProfileCode"]);
                      if($schoolRoleCode == "PAR"){
                        if($groupDetailsArr["groupTypeId"] == "3"){
                          $schoolUserRoleDTO->setBaseProfileCode($roleDetailsArr["baseProfileCode"]);
                        }else{
                          $schoolUserRoleDTO->setBaseProfileCode(null);
                        }
                      }
                      break;*/
                        
                        if($schoolRoleCode == "PAR"){
                        if($groupDetailsArr["groupTypeId"] == "3"){
                          $schoolUserRoleDTO->setBaseProfileCode($roleDetailsArr["baseProfileCode"]);
                          break;
                        }else{
                          $schoolUserRoleDTO->setBaseProfileCode(null);
                        }
                      }else{
                        $schoolUserRoleDTO->setBaseProfileCode($roleDetailsArr["baseProfileCode"]);
                        break;
                      }
                      
                    }
                  }
                }
              }
            }
          }
        }
      }
      // start get user exist in school
      
      $baseProfileCode = $schoolUserRoleDTO->getBaseProfileCode();
      
      if(empty($baseProfileCode)){
        $schoolUserRoleDTO->setBaseProfileCode($schoolUserRoleDTO->getProfileCode());
      }
      
      if(!empty($TeaBaseProfileCode) && $schoolRoleCode == "TEA"){
        $schoolUserRoleDTO->setBaseProfileCode($TeaBaseProfileCode);
      }
      
      $groupUsersDetail = $groupDaoObj->getexistUserInGroup($groupId, $uuid);
      if (empty($groupUsersDetail)) {
        if ($schoolRoleCode == "STU" && $groupTypeCode == SECTION) {
          $this->checkRollNumberForASection($groupUserDTO->getGroupCode(), $groupId, $groupUserDTO->getRollNumber());
        }
        $actionedBy = $groupUserDTO->getActionedBy();
        $activeDate = $groupUserDTO->getActiveDate();
        $deactiveDate = $groupUserDTO->getDeactiveDate();
        $groupUserDTO->setGroupId($groupId);
        $groupUserDTO->setGroupUserRoles($schoolUserRoleDTO);
        $groupUserModel = $this->translators->populateGroupUserModelFromDto($groupUserDTO);
        $groupUserModel->setIsActive(ACTIVE);
        $groupUserModel->setGroupRoleId(GMEM); //define user group role in constant
        $groupUserModel->setGroup($groupDetails);
        $groupUserDetails = $groupDaoObj->addUserDAO($groupUserModel);
        $this->solrInsertData($groupDaoObj, $groupUserDetails, $schoolRoleCode);
      } else {
        $flag = 0;
        $updateFlag = 0;
        if (!empty($groupUsersDetail[0]->getGroupUserRoles()[0])) {
          foreach ($groupUsersDetail[0]->getGroupUserRoles() as $key => $groupUserRole) {
            if ($groupUserDTO->getSchoolRoleCode() == $groupUserRole->getSchoolRoleCode()) {
              if($groupUserRole->getActive() == ACTIVE && $groupUsersDetail[0]->getIsActive() == ACTIVE){
                $flag = 1;
                break;
              }else{
                  $updateFlag = 1;
                  $params['isSingle'] = 1;
                  $params['key'] = $key;
                  $groupUserRole->setActive(ACTIVE);
                  $groupUsersDetail[0]->setIsActive(ACTIVE);
                  
                /*if($IsUserActiveInSchoolGroup == ACTIVE && $IsUserRoleActiveInSchoolGroup == ACTIVE && $groupTypeCode != SCHOOL){
                  $updateFlag = 1;
                  $params['isSingle'] = 1;
                  $params['key'] = $key;
                  $groupUserRole->setActive(ACTIVE);
                  $groupUsersDetail[0]->setIsActive(ACTIVE);
                }elseif($groupTypeCode == SCHOOL && ($IsUserActiveInSchoolGroup != ACTIVE || $IsUserRoleActiveInSchoolGroup != ACTIVE)){
                  $updateFlag = 1;
                  $params['isSingle'] = 1;
                  $params['key'] = $key;
                  $groupUserRole->setActive(ACTIVE);
                  $groupUsersDetail[0]->setIsActive(ACTIVE);
                }else{
                  throw(new \InvalidArgumentException("User/UserRole already deactive in school group with this schoolRoleCode:" . $groupUserDTO->getSchoolRoleCode(), 409));
                }*/
              }
            }
          }
          if($groupUserDTO->getGroupTypeCode() == SCG) {
            if ($flag == 1) {
                throw(new \InvalidArgumentException("User Role already exist with this schoolRoleCode:" . $groupUserDTO->getSchoolRoleCode(), 409));
            }
          }
        }
        if ($flag == 0) {
          if($updateFlag == 1){
            $groupUserDetails = $groupDaoObj->updateProfile($groupUsersDetail[0], $params);
            $this->solrInsertData($groupDaoObj, $groupUsersDetail[0], $schoolRoleCode);
          }else{
            $groupUserRoleModel = $this->translators->populateGroupUserRoleModelFromDto($schoolUserRoleDTO);
            $groupUsersDetail[0]->setGroupUserRoles($groupUserRoleModel);
            $groupUserDetails = $groupDaoObj->addUserDAO($groupUsersDetail[0]);
            $this->solrInsertData($groupDaoObj, $groupUserDetails, $schoolRoleCode);
          }
        }
      }
      
      //start get user exist or not in school group
      $userSchoolGroupDetails = array();
      $groupTypeArr = array(SCHOOL, APPLICATIONUSER);
        if (!in_array($groupTypeCode, $groupTypeArr)) {
          if(!empty($userExistInSchoolArr)){
            $arr = array("SUBT", "SECT");
            if (in_array($schoolRoleCode, $arr)) {
              $schoolRoleCode = "TEA";
            }
            foreach($userExistInSchoolArr as $groupDetailsArr){
                if($groupDetailsArr["groupTypeId"] == "3" && $groupDetailsArr["isActive"] == ACTIVE){
                  if(!empty($groupDetailsArr["groupUsers"])){
                    foreach($groupDetailsArr["groupUsers"] as $userDetailsArr){
                      if($uuid == $userDetailsArr["uuid"] && $userDetailsArr["isActive"] ==  ACTIVE){
                        if(!empty($userDetailsArr)){
                          foreach($userDetailsArr["groupUserRole"] as $roleDetailsArr){
                            if($roleDetailsArr["schoolRoleCode"] == $schoolRoleCode && $roleDetailsArr["active"] ==  ACTIVE){
                                $userSchoolGroupDetails["groupCode"] =  $groupDetailsArr["groupCode"];
                                $userSchoolGroupDetails["schoolCode"] =  $groupDetailsArr["schoolCode"];
                                $userSchoolGroupDetails["ayid"] =  $groupDetailsArr["ayid"];
                                $userSchoolGroupDetails["groupTypeId"] =  $groupDetailsArr["groupTypeId"];
                                $userSchoolGroupDetails["groupId"] =  $groupDetailsArr["groupId"];
                                $userSchoolGroupDetails["uuid"] =  $userDetailsArr["uuid"];
                                //$userSchoolGroupDetails["schoolRoleCode"] =  $roleDetailsArr["schoolRoleCode"];
                                $userSchoolGroupDetails["schoolRoleCode"] =  $schoolRoleCode;
                                break;
                            }
                          }
                        }
                      }
                    }
                  }
                } 
          }
        }
      } //end get user exist or not in school group
      if($groupTypeCode != SCG) {
         if(!empty($userSchoolGroupDetails)){
            //add  remove function
            $this->deleteUserRoleFromGroup($userSchoolGroupDetails["groupCode"], $userSchoolGroupDetails["uuid"], $userSchoolGroupDetails["schoolRoleCode"]);
         }
      }
      
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:addUserToGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $groupUserDetails;
  }
  /*
  function studentDetails(){
          //die("hi");
      ///echo $groupDetailDTO->getUuid();
      $uuid = $groupUserDTO->getUuid();
      $ayid = $groupUserDTO->getAyid();
      $schoolCode = $groupUserDTO->getSchoolCode();
      
      $param["schoolCodeArr"][0] = $schoolCode;
      $param["ayidArr"][0] = $ayid;
      $param["uuidArr"][0] = $uuid;
      $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
      $param["groupUserActive"] = ACTIVE;
      $param["roleActive"] = ACTIVE;
      $userExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
      $userExistInGroupArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($userExistInGroup);
     // print_r($userExistInGroupArr);
      if(!empty($userExistInGroupArr)){
        if($schoolRoleCode == "STU"){
          foreach($userExistInGroupArr as $groupDetailsArr){
            
            //student exist or not in school group
            if($groupDetailsArr["groupTypeId"] == "3" && $groupDetailsArr["isActive"] == ACTIVE){
              if(!empty($groupDetailsArr["groupUsers"])){
                foreach($groupDetailsArr["groupUsers"] as $userDetailsArr){
                  if($uuid == $userDetailsArr["uuid"] && $userDetailsArr["isActive"] ==  ACTIVE){
                    if(!empty($userDetailsArr)){
                      foreach($userDetailsArr["groupUserRole"] as $roleDetailsArr){
                        if($roleDetailsArr["schoolRoleCode"] == $schoolRoleCode && $roleDetailsArr["active"] ==  ACTIVE){
                          if($groupTypeCode==SCHOOL){
                            throw(new \InvalidArgumentException("User already exist in the school group : ". $groupDetailsArr["groupCode"]." with schoolRoleCode:" . $schoolRoleCode, 409));
                          }else{
                            $userSchoolGroupDetails["groupCode"] =  $groupDetailsArr["groupCode"];
                            $userSchoolGroupDetails["schoolCode"] =  $groupDetailsArr["schoolCode"];
                            $userSchoolGroupDetails["ayid"] =  $groupDetailsArr["ayid"];
                            $userSchoolGroupDetails["groupTypeId"] =  $groupDetailsArr["groupTypeId"];
                            $userSchoolGroupDetails["groupId"] =  $groupDetailsArr["groupId"];
                            $userSchoolGroupDetails["uuid"] =  $userDetailsArr["uuid"];
                            $userSchoolGroupDetails["schoolRoleCode"] =  $roleDetailsArr["schoolRoleCode"];
                          }
                          
                        }
                      }
                    }
                  }
                }
              }
            } // end for section group
            
            //student exist or not in section group
            if($groupDetailsArr["groupTypeId"] == "1" && $groupDetailsArr["isActive"] == ACTIVE){
              if(!empty($groupDetailsArr["groupUsers"])){
                foreach($groupDetailsArr["groupUsers"] as $userDetailsArr){
                  if($uuid == $userDetailsArr["uuid"] && $userDetailsArr["isActive"] ==  ACTIVE){
                    if(!empty($userDetailsArr)){
                      foreach($userDetailsArr["groupUserRole"] as $roleDetailsArr){
                        if($roleDetailsArr["schoolRoleCode"] == $schoolRoleCode && $roleDetailsArr["active"] ==  ACTIVE){
                          throw(new \InvalidArgumentException("User already exist in the section group : ". $groupDetailsArr["groupCode"]." with schoolRoleCode:" . $schoolRoleCode, 409));
                        }
                      }
                    }
                  }
                }
              }
            } // end for section group
          }
        }
      }
  }
*/
  /*
  private function addUser($groupDetails, $groupUserDTO) {
    $groupUserDetails = '';
    try {
      $groupDetailDTO = $this->translators->populateDTOFromGroupModel($groupDetails);
      $uuid = $groupUserDTO->getUuid();
      $groupDaoObj = new GroupDAO();
      $groupTypeId = $groupDetailDTO->getGroupTypeId();
      $groupTypeCodeDetails = $groupDaoObj->getGroupTypeByGroupTypeId($groupTypeId);
      if (empty($groupTypeCodeDetails)) {
        throw(new \InvalidArgumentException("Group Type Code Details does not exist", 409));
      }
      $groupTypeCode = $groupTypeCodeDetails[0]->getGroupTypeCode();
     // @Fliplearn 3.0 : ATG group removed from $role_arr 
    //  $role_arr = array("SCHG" => array("PRI", "STU", "SAD", "STF", "PAR", "TEA"), "SUBG" => array("SUBT", "STU"), "SECG" => array("SECT", "STU"), "SCG" => array("PRI", "CLT", "SECT", "STU", "SAD", "STF", "SUBT", "PAR", "TEA"), "ATG" => array("TEA"), "AUG" => array("SPA"), "CUG" => array());
      $role_arr = array("SCHG" => array("PRI", "STU", "SAD", "STF", "PAR", "TEA"), "SUBG" => array("SUBT", "STU"), "SECG" => array("SECT", "STU"), "SCG" => array("PRI", "CLT", "SECT", "STU", "SAD", "STF", "SUBT", "PAR", "TEA"), "AUG" => array("SPA"), "CUG" => array());
      $school_role_arr = array("PRI" => "School Principal", "SECT" => "Class Teacher", "STU" => "Student", "SAD" => "School Admin", "STF" => "School Staff", "SUBT" => "Subject Teacher", "PAR" => "Parent", "TEA" => "Teacher", "SPA" => "Application User");
      if (empty($role_arr[$groupTypeCode])) {
        throw(new \InvalidArgumentException("User can not add in this group" . $groupTypeCode, 409));
      }
      $schoolRoleCode = $groupUserDTO->getSchoolRoleCode();
      if (!in_array($schoolRoleCode, $role_arr[$groupTypeCode])) {
        throw(new \InvalidArgumentException($school_role_arr[$schoolRoleCode] . " can not add in this group with groupTypeCode:" . $groupTypeCode, 409));
      }
       @Fliplearn 3.0 : Parent can be added to Section Group now. No case will now arise to bulk add parent in group. 
       * if ($groupUserDTO->getSchoolRoleCode() == PAR) {
       * $group_type_arr = array(SCHOOL, CUSTOM);
       * if (!in_array($groupTypeCode, $group_type_arr)) {
       * throw(new \InvalidArgumentException("Parent can not add in this group with groupTypeCode:" . $groupTypeCode, 3035));
       * }
       * }
       
      $groupId = $groupDetailDTO->getGroupId();
      $groupUsersDetail = $groupDaoObj->getexistUserInGroup($groupId, $uuid);
      $schoolRoleCode = $groupUserDTO->getSchoolRoleCode();
      //$profileCode = substr(rand() . microtime(), 0, 10);
      $profileCode = $this->util->generateCode();
      $schoolRoleDetails = $groupDaoObj->getSchoolRoleDetailsBySchoolRoleCode($groupUserDTO->getSchoolRoleCode());
      $schoolRoleDescription = $schoolRoleDetails[0]->getDescription();
      $teaRoleArr = array("SUBT", "SECT");
      if (in_array($schoolRoleCode, $teaRoleArr)) {
        $param["groupCodeArr"][0] = $groupUserDTO->getGroupCode();
        $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
        $param["roleActive"] = ACTIVE;
        $userExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
        $modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($userExistInGroup);
        // @Fliplearn 3.0 : changes for more than one subject teacher
        if (!empty($modelArr) && $schoolRoleCode == "SECT") {
          throw(new \InvalidArgumentException("There can not be more than one Section teacher in the Sectiongroup:" . $groupUserDTO->getGroupCode() . " and schoolRoleCode:" . $schoolRoleCode, 409));
        }else if((count($modelArr)== 2) && $schoolRoleCode == "SUBT"){
          throw(new \InvalidArgumentException("There can not be more than two Subject teacher in the subjectgroup:" . $groupUserDTO->getGroupCode() . " and schoolRoleCode:" . $schoolRoleCode, 409));  
        }
      }
      
      if ($schoolRoleCode == "STU") {
        if($groupTypeCode == SECTION) {
          $param["schoolCodeArr"][0] = $groupDetailDTO->getSchoolCode();
          $param["ayidArr"][0] = $groupDetailDTO->getAyid();
          $param["groupTypeIdArr"][0] = $groupTypeId;
          $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
          $param["uuidArr"][0] = $uuid;
          $param["roleActive"] = ACTIVE;
          $param["groupUserActive"] = ACTIVE;
          $stuExistInGroup = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
          //$modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($stuExistInGroup);
          if (!empty($stuExistInGroup)) {
            throw(new \InvalidArgumentException("User already exist in the section group with schoolRoleCode:" . $schoolRoleCode, 409));
          }
        }
      }
      $UserDetails = $this->getUserDetailsByUuid($uuid);
      if (empty($UserDetails->getUser()[0])) {
        throw(new \InvalidArgumentException($UserDetails->getError()["internalMessage"], 409));
      }
      if (!empty($UserDetails->getUser()[0]->getFirstName())) {
        $profileName = $UserDetails->getUser()[0]->getFirstName() . " ";
      }
      if (!empty($UserDetails->getUser()[0]->getMiddleName())) {
        $profileName .= $UserDetails->getUser()[0]->getMiddleName() . " ";
      }
      if (!empty($UserDetails->getUser()[0]->getLastName())) {
        $profileName .= $UserDetails->getUser()[0]->getLastName() . " ";
      }
      $profileName .= $schoolRoleDescription;
      if ($schoolRoleCode == SPA) {
        $profileName = "Fliplearn Super Admin";
      }
      $active = ACTIVE;
      $schoolUserRoleDTO = $this->translators->populateSchoolUserRoleDTOFromParmeter($schoolRoleCode, $profileCode, $profileName, $active);
      $groupTypeArr = array(SCHOOL, APPLICATIONUSER);
      if (in_array($groupTypeCode, $groupTypeArr)) {
        $schoolUserRoleDTO->setBaseProfileCode($schoolUserRoleDTO->getProfileCode());
      } else {
        //get base profile from SCH group
        $schoolCode = $groupDetailDTO->getSchoolCode();
        $groupId = $groupDetailDTO->getGroupId();
        $ayid = $groupDetailDTO->getAyid();
        $schoolGroupTypeDetails = $groupDaoObj->getGroupTypeByGroupTypeCode(SCHOOL); // for geting school group type id     
        $schoolGroupTypeID = $schoolGroupTypeDetails[0]->getId();
        //$schoolGroupUser = $groupDaoObj->getGroupsUserDetails($schoolGroupTypeID, $schoolCode, $uuid);
        $schoolGroupUser = $groupDaoObj->getGroupUserWithRoleDetails($schoolGroupTypeID, $schoolCode, $uuid, $ayid);
        $baseProfileCode = "";
        if (empty($schoolGroupUser)) {
          throw(new \InvalidArgumentException("User does not exist in the school with this SchoolCode:" . $schoolCode . " and Ayid:" . $ayid, 409));
        } elseif (empty($schoolGroupUser[0]->getGroupUserRoles()[0])) {
          throw(new \InvalidArgumentException("User role does not exist in the school with this uuid:" . $uuid . "SchoolCode: " . $schoolCode . " schoolRoleCode:" . $schoolUserRoleDTO->getSchoolRoleCode(), 409));
        }
        
        $IsUserActiveInSchoolGroup = $schoolGroupUser[0]->getIsActive();
          
        foreach ($schoolGroupUser[0]->getGroupUserRoles() as $groupUserRole) {
          $userRole = $schoolUserRoleDTO->getSchoolRoleCode();
          $arr = array("SUBT", "SECT");
          if (in_array($userRole, $arr)) {
            $userRole = "TEA";
          }
          if ($userRole == $groupUserRole->getSchoolRoleCode()) {
            $baseProfileCode = $groupUserRole->getBaseProfileCode();
            $IsUserRoleActiveInSchoolGroup = $groupUserRole->getActive();
          }
        }
        
        if (empty($baseProfileCode)) {
          throw(new \InvalidArgumentException("baseProfileCode does not exist in the school with this schoolRoleCode:" . $schoolUserRoleDTO->getSchoolRoleCode(), 409));
        }
        $schoolUserRoleDTO->setBaseProfileCode($baseProfileCode);
      }
      if (empty($groupUsersDetail)) {
        if ($schoolRoleCode == "STU" && $groupTypeCode == SECTION) {
          $this->checkRollNumberForASection($groupUserDTO->getGroupCode(), $groupId, $groupUserDTO->getRollNumber());
        }
        $actionedBy = $groupUserDTO->getActionedBy();
        $activeDate = $groupUserDTO->getActiveDate();
        $deactiveDate = $groupUserDTO->getDeactiveDate();
        //$groupUserDTO = $this->translators->populateDTOFromGroupsUsersSRO($groupUserSRO);
        ///$groupModel = $this->translators->populateGroupModelFromDTO($groupDetailDTO);
        //$groupModel->setGroupId($groupId);
        $groupUserDTO->setGroupId($groupId);
        $groupUserDTO->setGroupUserRoles($schoolUserRoleDTO);
        $groupUserModel = $this->translators->populateGroupUserModelFromDto($groupUserDTO);
        $groupUserModel->setIsActive(ACTIVE);
        $groupUserModel->setGroupRoleId(GMEM); //define user group role in constant
        $groupUserModel->setGroup($groupDetails);
        $groupUserDetails = $groupDaoObj->addUserDAO($groupUserModel);
        $this->solrInsertData($groupDaoObj, $groupUserDetails, $schoolRoleCode);
        if ($schoolRoleCode == "STU") {
          //sync parent profile
          //call syncParentProfiles function GR14 and return status of api
          $this->syncParentProfiles($uuid, $actionedBy, $groupDetailDTO->getSchoolCode(), $groupDetailDTO->getAyid());
        }
      } else {
        $flag = 0;
        $updateFlag = 0;
        if (!empty($groupUsersDetail[0]->getGroupUserRoles()[0])) {
          foreach ($groupUsersDetail[0]->getGroupUserRoles() as $key => $groupUserRole) {
            if ($groupUserDTO->getSchoolRoleCode() == $groupUserRole->getSchoolRoleCode()) {
              if($groupUserRole->getActive() == ACTIVE && $groupUsersDetail[0]->getIsActive() == ACTIVE){
                $flag = 1;
                break;
              }else{
                if($IsUserActiveInSchoolGroup == ACTIVE && $IsUserRoleActiveInSchoolGroup == ACTIVE && $groupTypeCode != SCHOOL){
                  $updateFlag = 1;
                  $params['isSingle'] = 1;
                  $params['key'] = $key;
                  $groupUserRole->setActive(ACTIVE);
                  $groupUsersDetail[0]->setIsActive(ACTIVE);
                }elseif($groupTypeCode == SCHOOL && ($IsUserActiveInSchoolGroup != ACTIVE || $IsUserRoleActiveInSchoolGroup != ACTIVE)){
                  $updateFlag = 1;
                  $params['isSingle'] = 1;
                  $params['key'] = $key;
                  $groupUserRole->setActive(ACTIVE);
                  $groupUsersDetail[0]->setIsActive(ACTIVE);
                }else{
                  throw(new \InvalidArgumentException("User/UserRole already deactive in school group with this schoolRoleCode:" . $groupUserDTO->getSchoolRoleCode(), 409));
                }
              }
            }
          }
          if ($flag == 1) {
            throw(new \InvalidArgumentException("User Role already exist with this schoolRoleCode:" . $groupUserDTO->getSchoolRoleCode(), 409));
          }
        }
        if ($flag == 0) {
          if($updateFlag == 1){
            $groupUserDetails = $groupDaoObj->updateProfile($groupUsersDetail[0], $params);
            $this->solrInsertData($groupDaoObj, $groupUsersDetail[0], $schoolRoleCode);
          }else{
            $groupUserRoleModel = $this->translators->populateGroupUserRoleModelFromDto($schoolUserRoleDTO);
            $groupUsersDetail[0]->setGroupUserRoles($groupUserRoleModel);
            $groupUserDetails = $groupDaoObj->addUserDAO($groupUsersDetail[0]);
            $this->solrInsertData($groupDaoObj, $groupUserDetails, $schoolRoleCode);
            if ($schoolRoleCode == "STU") {
              $this->syncParentProfiles($uuid, $actionedBy, $groupDetailDTO->getSchoolCode(), $groupDetailDTO->getAyid());
            }
          }
        }
      }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:addUserToGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $groupUserDetails;
  }
  */
  /**
   * Function solrInsertData.
   * This function is insert the data into groups solr collection.
   * @author Prasoon Saxena <prasoon.saxena@flilearn.com>
   */
  function solrInsertData ($groupDaoObj, $groupUserDetails, $schoolRoleCode=false) 
  {
    /* start - restrict solr insert data for Teacher role by Afsana*/
    if (isset($schoolRoleCode) && $schoolRoleCode == "TEA")
        return;
    /* end - restrict solr insert data for Teacher role by Afsana*/

    echo PHP_EOL;
    //echo "Count :"; echo count($groupUserDetails->getGroupUserRoles());  die();
    //var_dump($groupUserDetails->getGroupUserRoles()); die();
    //die();
    $groupUserSolrFinalArr=array();

    foreach ($groupUserDetails->getGroupUserRoles() as $groupUserRole) {
      if (isset($schoolRoleCode) && $schoolRoleCode == $groupUserRole->getSchoolRoleCode()) {
        $admissionNo = null;


        if ($schoolRoleCode == STUDENT_ROLE_CODE) {
          try {
            $request = new \App\External\School\Request\GetSchoolUserAdmissionRequest;
            $request->setUuid($groupUserDetails->getUuid());
            $schoolService = new SchoolService;

            // $admissionNoresponse = $schoolService->getSchoolUserAdmission($request);

            // if ($admissionNoresponse->getError()) {
            //   $admissionNo = null;
            // } else {
            //   $schoolUserAdmissionSMO = $admissionNoresponse->getSchool();
            //   $admissionNo = $schoolUserAdmissionSMO->getAdmissionNo();
            // }
          } catch (FlipHTTPException $e) {
            $this->logger->error("Exception occured in groupComponent solrInsertData function message: " . $e->getMessage());
            throw new BackendException($e->getMessage(), 500);
          }
        }

        // die("test");

        $groupUserSolrArr = array();
        $groupMasterActive = ($this->util->equals((int)$groupUserDetails->getGroup()->getIsActive(), ACTIVE)) ? "true" : "false";
        $groupUserActive = ($this->util->equals((int)$groupUserDetails->getIsActive(), ACTIVE)) ? "true" : "false";
        $groupUserSolrArr['id'] = $groupUserRole->getId();
        $groupUserSolrArr['base_profile_code'] = $groupUserRole->getBaseProfileCode();
        $groupUserSolrArr['ref_code'] = $groupUserRole->getRefCode();
        $groupUserSolrArr['profile_name'] = $groupUserRole->getProfileName();
        $groupUserSolrArr['display_name'] = $groupUserDetails->getGroup()->getDisplayName();
        $groupUserSolrArr['group_logo'] = $groupUserDetails->getGroup()->getGroupLogo();
        $groupUserSolrArr['group_name'] = $groupUserDetails->getGroup()->getGroupName();
        $groupUserSolrArr['group_code'] = $groupUserDetails->getGroup()->getGroupCode();
        $groupUserSolrArr['uuid'] = $groupUserDetails->getUuid();
        $groupUserSolrArr['profile_code'] = $groupUserRole->getProfileCode();
        $groupUserSolrArr['school_role_code'] = $groupUserRole->getSchoolRoleCode();
        $groupUserSolrArr['GroupUserRoleActive'] = $groupUserRole->getActive();
        $groupUserSolrArr['GroupMasterActive'] = $groupMasterActive;
        $groupUserSolrArr['GroupUserActive'] = $groupUserActive;
        $groupUserSolrArr['schoolCode'] = $groupUserDetails->getGroup()->getSchoolCode();
        $groupUserSolrArr['ayid'] = $groupUserDetails->getGroup()->getAyid();
        $groupUserSolrArr['admission_no'] = $admissionNo;
        $groupTypeId = $groupUserDetails->getGroup()->getGroupTypeId();
        $groupTypeCodeDetails = $groupDaoObj->getGroupTypeByGroupTypeId($groupTypeId);
        $groupTypeCode = $groupTypeCodeDetails[0]->getGroupTypeCode();
        $groupUserSolrArr['group_type_code'] = $groupTypeCode;

        array_push($groupUserSolrFinalArr, $groupUserSolrArr);
        //$groupUserSolrJson = json_encode(array($groupUserSolrArr), true);
      
      // $cmd = 'php ' . $_SERVER['DOCUMENT_ROOT'] . '/solrSync/solrInsert.php CreateGroupUserRole ' . escapeshellarg($groupUserSolrJson);

        //echo $cmd; die;
        //system("nohup $cmd", $return);
        //$this->logger->debug("[GroupComponent:solrInsertData] Group solrInsertData API Request " . $cmd);
        //$this->logger->debug("[GroupComponent:solrInsertData] Group solrInsertData API Response " . $return);
      }
    }

   // echo "sss"; die();
    $groupUserSolrJson = json_encode(array($groupUserSolrFinalArr[0]), true);
    //echo $groupUserSolrJson; die();
    $url = SOLR_URL . "/groups/update?softCommit=true";
    $this->curl($url, $groupUserSolrJson);
    return true;
  }

  function curl($url, $paramater)
{
  try {
    //Initiate cURL.
    $ch = curl_init($url);

    //Tell cURL that we want to send a POST request.
    curl_setopt($ch, CURLOPT_POST, 1);

    curl_setopt($ch, CURLOPT_HEADER, true);    // we want headers
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);

    //Attach our encoded JSON string to the POST fields.
    curl_setopt($ch, CURLOPT_POSTFIELDS, $paramater);

    //Set the content type to application/json
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

    //Execute the request
    $result = curl_exec($ch);

    $err = curl_errno($ch);
        print_r($result); 
        print_r($err); die();


    curl_close($ch);
    if ($err) {
      new \Exception("Error in backend API." . curl_error($ch), curl_errno($ch));
    }

    $log = "Request: " . $paramater . " Response1 : " . json_encode($result, true) . " Date : " . date("d-m-Y-H:i:s") . "\n";
  } catch (Exception $e) {
    $log = "Request:Error" . $paramater . " Response2 : " . json_encode($result, true) . "errorMessage : " . $e->getMessage() . " Date : " . date("d-m-Y-H:i:s") . "\n";
  }

}
  
  public function deleteUserRoleFromGroup($groupCode, $uuid, $schoolRoleCode) {
    $this->validator->validateUserRoleFromGroup($groupCode, $uuid, $schoolRoleCode); 
    try {
        $status = false;
        $groupDaoObj = new GroupDAO();
        $param = array();
        $param["groupCodeArr"][0] = $groupCode;
        $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
        $param["uuidArr"][0] = $uuid;
        $param["groupUserActive"] = ACTIVE;
        $param["roleActive"] = ACTIVE;
        
        //groupActive
        $userRoleDetails = $groupDaoObj->getGroupUserRoleDetailsByParam($param);
        if (empty($userRoleDetails)) {
            throw(new \InvalidArgumentException("This user does not exist in this group with this role!", 409));
        }
        //$modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($userRoleDetails);
        
        $param = array();
        $param["groupCodeArr"][0] = $groupCode;
        $param["schoolRoleCodeArr"][0] = $schoolRoleCode;
        $param["uuidArr"][0] = $uuid;
        $param["groupUserActive"] = ACTIVE;
        $param["groupActive"] = ACTIVE;
        
        $userGroupDetails = $groupDaoObj->getGroupUserDetailsByParam($param);
        if (empty($userGroupDetails)) {
            throw(new \InvalidArgumentException("This user does not exist in this group!", 409));
        }
        
        $modelArr1 = $this->translators->populateGroupUserDetailsArrFromModel($userGroupDetails);
        if (empty($modelArr1)) {
            throw(new \InvalidArgumentException("This user does not exist for groupCode : ".$param["groupCodeArr"][0]." Uuid : ".$param["uuidArr"][0]." Role : ".$param["schoolRoleCodeArr"][0], 409));
        }
        
       foreach ($modelArr1 as $key => $groupModel) {
          foreach($groupModel['groupUsers'] as $groupUsers) {
              foreach($groupUsers as $key => $groupUser) {
                 foreach($groupUser as $key => $groupUserRole) {
                     $roleCount = count($groupUser);
                 }
              }
          }
       }
       
       if($roleCount>1) {
        $groupModel = $groupDaoObj->removeUserRoleFromGroup($userRoleDetails);
       } else if ($roleCount==1) {
        $groupModel = $groupDaoObj->removeUserRoleFromGroup($userGroupDetails); 
       }
       if ($groupModel) {
        $status = true;
        $this->solrDeleteData($groupCode, $uuid, $schoolRoleCode);
       }
    } catch (\MySQLException $e) {
      $this->logger->error("[GroupComponent:deleteUserRoleFromGroup:MySQLException:Message:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $status;
  }
  
  /**
   * Function solrDeleteData.
   * This function is delete the data from groups solr collection.
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  function solrDeleteData($groupCode, $uuid, $role) {
      
        $deleteSolrArr = array();
        $deleteSolrArr['group_code'] = $groupCode;
        $deleteSolrArr['uuid'] = $uuid;
        $deleteSolrArr['role'] = $role;
       
        $groupUserSolrJson = json_encode(array($deleteSolrArr), true);
        $cmd = 'php ' . $_SERVER['DOCUMENT_ROOT'] . '/solrSync/solrDelete.php deleteUserRole ' . escapeshellarg($groupUserSolrJson);
        $return = system("nohup $cmd",$return);
        $this->logger->debug("[GroupComponent:solrDeleteData] Group solrInsertData API Request " . $cmd);
        $this->logger->debug("[GroupComponent:solrDeleteData] Group solrInsertData API Response " . $return);
    return true;
  }
  
    /**
   * Function getUniqueUserProfileFromSchoolByRole.
   * This function is used get Search Groups. 
   * @author Gaurav Sengar <gaurav@incaendo.com>
   */
  public function getUniqueProfileFromSchoolByRole(\App\HTTP\GetProfileListForUser $searchGroupsSRO, $pageNum, $pageSize) {
    
    $this->validator->validateGetGroupUniqueUserProfileFromDB($searchGroupsSRO);
    $this->validator->validatePageNumAndPageSize($pageNum, $pageSize);
    try {
      $searchDTO = $this->translators->populateUserListDTOFromSRO($searchGroupsSRO);
      $condition = $this->getPageLimit($pageNum, $pageSize);
      
      $schoolRoleCode = $searchDTO->getSchoolRoleCode();
      $schoolRoleCodeArr = array("STUDENTPARENT"=>array("STU", "PAR"));
      
      if(empty($schoolRoleCodeArr[$schoolRoleCode])){
        throw(new \InvalidArgumentException("Invalid schoolRoleCode : ".$schoolRoleCode, 409));
      }
      
      $param = array();
      $param["schoolCodeArr"] = $searchDTO->getSchoolCode();
      $param["ayidArr"] = $searchDTO->getAyid();
      $param["schoolRoleCodeArr"] = $schoolRoleCodeArr[$schoolRoleCode];
      $param["groupActive"] = $searchDTO->getGroupActive();
      $param["groupUserActive"] = $searchDTO->getGroupUserActive();
      $param["roleActive"] = $searchDTO->getRoleActive();
      $param["pageNum"] = $pageNum;
      $param["pageSize"] = $pageSize;
      
      if ($pageSize > MAX_LIMIT) {
        throw new GroupException('Please set lower Page Size Limit.', 409);
      }
      
      $groupDaoObj = new GroupDAO();

      /* GroupModel */ $groupDetails = $groupDaoObj->getGroupUniqueUserRoleDetailsByParam($param);
      if (empty($groupDetails)) {
        throw(new \InvalidArgumentException("No Record found with getUniqueUserProfileFromSchoolByRole request.", 409));
      }
      $modelArr = $this->translators->populateGroupUserRoleDetailsArrFromModel($groupDetails);
      
      $groupDetailsSRO = array();
      if (!empty($modelArr)) {
        foreach ($modelArr as $key => $groupModel) {
          $groupDetailsDTO = $this->translators->populateGroupDetailsFromModel($groupModel);
          $groupDetailsSRO[$key] = $this->translators->populateGroupDetailSROFromDTO($groupDetailsDTO);
        }
      } else {
        throw new GroupException('Record not found with getUniqueProfileFromSchoolByRole request.', 409);
      }
    } catch (\MySQLException $e) {
      $this->logger->error("App\Component\GroupComponent:getUniqueProfileFromSchoolByRole:" . $e->getMessage());
      throw new GroupException($e->getMessage(), 500);
    }
    return $groupDetailsSRO;
  }
  
  function addUserBySchoolAndRole($addUserBySchoolAndRoleRequest) {
    $this->validator->validateAddUserBySchoolAndRole($addUserBySchoolAndRoleRequest);
    $schoolCode = $addUserBySchoolAndRoleRequest->getSchoolCode();
    $ayid = $addUserBySchoolAndRoleRequest->getAyid();
    $uuid = $addUserBySchoolAndRoleRequest->getUuid();
    $schoolRoleCode = $addUserBySchoolAndRoleRequest->getSchoolRoleCode();
    $actionedBy = $addUserBySchoolAndRoleRequest->getActionedBy();
    $activeDate = date('Y-m-d');
    $deactiveDate = date('Y-m-d', strtotime('+1 years'));
    $groupDAO = new GroupDAO();
    try {
      /* GroupModel */ $schoolGroupBySchoolCodeAndAyid = $groupDAO->getSchoolGroupBySchoolCodeAndAyid($ayid, $schoolCode, $groupTypeId = 3);
      $cnt = count($schoolGroupBySchoolCodeAndAyid);
      if ($cnt > 0) {
        foreach ($schoolGroupBySchoolCodeAndAyid as $groupBySchoolCode) {
          $schoolGroupCode =  $groupBySchoolCode->getGroupCode();
        }
        $groupUserSRO = $this->translators->populateGroupsUserSROFromParams($addUserBySchoolAndRoleRequest, $schoolGroupCode, $activeDate, $deactiveDate);
        $groupUserDetails = $this->addUserToGroup($groupUserSRO);
      } else {
        throw new GroupException("No Record found with schoolCode:" . $schoolCode . " and Ayid:" . $ayid, 404);
      }
      return true;
    } catch (\Exception $e) {
      throw new GroupException($e->getMessage(), $e->getCode());
    }
  }
  
  function solrUpdateData($id, $userGroupDetails) {    
    if (isset($userGroupDetails)) {
      $groupUserSolrArr = array();
      $groupUserSolrArr['id'] = $id;
      
      if (!empty($userGroupDetails->getRollNumber()) && $userGroupDetails->getRollNumber() != "null") {
        $groupUserSolrArr['roll_number'] = array("set" => (string) $userGroupDetails->getRollNumber());
      }
      
      if (!empty($userGroupDetails->getIsActive()) && $userGroupDetails->getIsActive() != "null") {
        $groupUserActive = ($this->util->equals((int)$userGroupDetails->getIsActive(), ACTIVE)) ? "true" : "false";
        $groupUserSolrArr['GroupUserActive'] = $groupUserActive;
      }
      
      $groupUserSolrJson = json_encode(array($groupUserSolrArr), true);

      $cmd = 'php ' . $_SERVER['DOCUMENT_ROOT'] . '/solrSync/solrInsert.php CreateGroupUserRole ' . escapeshellarg($groupUserSolrJson);

      system("nohup $cmd");
    }
    return true;
  }
  
  
  function getDashBoard0($schoolCode,$ayid){
   
    $this->validator->validateGetGroupsByGroupType($schoolCode, $ayid);
    try {
      $groupDAO = new GroupDAO();
      $conditionArr = array('schoolCodeArr'=>$schoolCode,'ayidArr'=>$ayid);
      /* GroupModel */ $UsersList = $groupDAO->getGroupUniqueUserDetailsByParam($conditionArr);
   // print_r($UsersList[0]->getGroupUsers()[0]->getGroupUserRoles()[0]->getSchoolRoleCode());exit;
      $RoleUuidArr = array();
      if (sizeof($UsersList) > 0) {
        foreach($UsersList as $k=>$groupLists){
            if(sizeof($groupLists->getGroupUsers())){
              foreach($groupLists->getGroupUsers() as $k2=>$groupUsers){
                 if(sizeof($groupUsers->getGroupUserRoles())>0){
                   foreach($groupUsers->getGroupUserRoles() as $k3=>$groupUserRole){                    
                     if($groupUserRole->getSchoolRoleCode() == 'SAD'){
                       $RoleUuidArr['ADMIN'][$groupUsers->getUuid()] = 1;                       
                     }elseif($groupUserRole->getSchoolRoleCode() == 'PRI'){
                       $RoleUuidArr['PRINCIPALS'][$groupUsers->getUuid()] = 1;
                     }elseif($groupUserRole->getSchoolRoleCode() == 'STU'){
                       $RoleUuidArr['STUDENTS'][$groupUsers->getUuid()] = 1;
                     }elseif($groupUserRole->getSchoolRoleCode() == 'PAR'){
                       $RoleUuidArr['PARENTS'][$groupUsers->getUuid()] = 1;
                     }elseif($groupUserRole->getSchoolRoleCode() == 'TEA' || $groupUserRole->getSchoolRoleCode() == 'SUBT' ||$groupUserRole->getSchoolRoleCode() == 'SECT'){
                       $RoleUuidArr['TEACHERS'][$groupUsers->getUuid()] = 1;
                     }
                   }
                 }
              }
            }          
        }        
       $groupsDashboardSRO = $this->translators->populateGroupsDashboardSROFromParams($RoleUuidArr);
      } else {
        throw new GroupException('No Record found with SchoolCode:'.$schoolCode.' and ayid:'.$ayid.' request.', 404);
      }
    } catch (\SqlException $e) {
      $this->logger->error("App\Component\GroupComponent:getDashBoard:" . $e->getMessage(), 503);
      throw new GroupException($e->getMessage());
    }
    return $groupsDashboardSRO;
  }
  
  
    function getDashBoard($schoolCode,$ayid){
   
    $this->validator->validateGetGroupsByGroupType($schoolCode, $ayid);
    try {
      $groupDAO = new GroupDAO();
      //$conditionArr = array('schoolCodeArr'=>$schoolCode,'ayidArr'=>$ayid);
 
      /* GroupModel */ $UsersList = $groupDAO->getDashboardCount($schoolCode,$ayid);
      
      if (sizeof($UsersList) > 0) {
        $roleList = array('ADMIN'=>0,'PARENTS'=>0,'PRINCIPALS'=>0,'STUDENTS'=>0,'TEACHERS'=>0);
        foreach($UsersList as $k=>$v){
          if($v['school_role_code1'] =='PAR'){
          $roleList['PARENTS'] = $v['total'];
          }
          if($v['school_role_code1'] =='PRI'){
          $roleList['PRINCIPALS'] = $v['total'];
          }
          if($v['school_role_code1'] =='SAD'){
          $roleList['ADMIN'] = $v['total'];
          }
          if($v['school_role_code1'] =='TEA'){
          $roleList['TEACHERS'] = $v['total'];
          }
          if($v['school_role_code1'] =='STU'){
          $roleList['STUDENTS'] = $v['total'];
          }
        }
      
       $groupsDashboardSRO = $this->translators->populateGroupsDashboardSROFromParams($roleList);
      } else {
        throw new GroupException('No Record found with SchoolCode:'.$schoolCode.' and ayid:'.$ayid.' request.', 404);
      }
    } catch (\SqlException $e) {
      $this->logger->error("App\Component\GroupComponent:getDashBoard:" . $e->getMessage(), 503);
      throw new GroupException($e->getMessage());
    }
    return $groupsDashboardSRO;
  }
  
  public function checkActiveUserForSection($sectionCode) {
    $return = true;
    $this->validator->validateCheckActiveUserForSection($sectionCode);
    try {
      $groupDAO = new GroupDAO();
      /* GroupComponent */ $checkActiveUserForSection = $groupDAO->checkActiveUserForSection(ACTIVE,'"STU","PAR"', $sectionCode, SECTION_GROUP_TYPE_ID, ACTIVE);
      if ($checkActiveUserForSection[0]['totalRow'] > 0) {
        throw new GroupException('Active user found with section code:'.$sectionCode, 409);
      }
      $return = false;
    } catch (\SqlException $e) {
      $this->logger->error("GroupComponent:checkActiveUserForSection:" . $e->getMessage(), 503);
      throw new GroupException($e->getMessage());
    }
    return $return;
  }
  
  /**
   * Function removeInactiveUserFromSection.
   * This function removes Inactive users from section by Section Code. 
   * @author Maninder Kumar <maninder@incaendo.com>
   */
  public function removeInactiveUserFromSection($sectionCode) {
    $this->validator->validateRemoveInactiveUserFromSection($sectionCode); 
    try {
        $groupDaoObj = new GroupDAO(); 
        $groupModels = $groupDaoObj->selectInactiveUserForSection($sectionCode);
        foreach($groupModels as $groupModel) {
          foreach($groupModel->getGroupUsers() as $groupUser) {
            $teacherRoleArray = array("SECT","SUBT","TEA");
            if(!empty($groupUser)) {
              foreach($groupUser->getGroupUserRoles() as $groupRoles) {
                if(!empty($groupRoles)) {
                  if(in_array($groupRoles->getSchoolRoleCode(), $teacherRoleArray)) {
                    $uuid = $groupUser->getUuid();
                    $groupUserSRO = new GroupUserSRO;
                    $groupUserSRO->setGroupCode($sectionCode);
                    $groupUserSRO->setUuid($uuid);
                    $groupUserSRO->setSchoolRoleCode($groupRoles->getSchoolRoleCode());
                    $groupUserSRO->setActionedBy($uuid);
                    $activeDate = date('Y-m-d');
                    $deactiveDate = date('Y-m-d', strtotime('+1 years'));
                    $groupUserSRO->setActiveDate($activeDate);
                    $groupUserSRO->setDeactiveDate($deactiveDate);
                    $this->removeRoleForUser($groupUserSRO, true);
                    $roleId = $groupRoles->getId();
                    $groupDaoObj->deleteUserRole($roleId);
                  } else {
                    $roleId = $groupRoles->getId();
                    $groupDaoObj->deleteUserRole($roleId);
                  }
                }
              }
              $userId = $groupUser->getId();
              $groupDaoObj->deleteInactiveUserFromSection($userId);
            }
          }
        }
        $status = true;
    } catch (\MySQLException $e) {
        $this->logger->error("[GroupComponent:removeInactiveUserFromSection:MySQLException:Message:" . $e->getMessage());
        throw new GroupException($e->getMessage(), 500);
    }
    return $status;
  }
	
	/**
		 * Function deleteSectiongroup.
		 * This function deletes the section by Section Code. 
		 * @author Maninder Kumar <maninder@incaendo.com>
		 */
		public function deleteSectionGroup($sectionCode) {
			$status = false;
			$this->validator->validateDeleteSectionGroup($sectionCode);
			try {
				$groupDaoObj = new GroupDAO();
				$sectionDetails = $groupDaoObj->getGrpUsersByGroupCode($sectionCode);
				if (!empty($sectionDetails)) {
					if ($this->checkActiveUserForSection($sectionCode)) {
						throw new GroupException('Active user found with section code:' . $sectionCode, 409);
					} else {
						$this->removeInactiveUserFromSection($sectionCode);
						$groupDaoObj = new GroupDAO();
						$groupDaoObj->deleteSectionGroup($sectionCode);
            $this->deleteSectionGroupFromSolr($sectionCode);
						$status = true;
					}
				} else {
					throw new GroupException('No Record found with section.', 409);
				}
			} catch (\MySQLException $e) {
				$this->logger->error("[GroupComponent:deleteSectionGroup:MySQLException:Message:" . $e->getMessage());
				throw new GroupException($e->getMessage(), 500);
			}
			return $status;
		}
    
    /**
   * Function solrDeleteData.
   * This function is delete the data from groups solr collection.
   * @author Udit Chandhoke <udit@incaendo.com>
   */
  function deleteSectionGroupFromSolr($groupCode) {

    $deleteSolrArr = array();
    $deleteSolrArr['group_code'] = $groupCode;

    $groupUserSolrJson = json_encode(array($deleteSolrArr), true);
    $cmd = 'php ' . $_SERVER['DOCUMENT_ROOT'] . '/solrSync/solrDelete.php deleteSelectionUser ' . escapeshellarg($groupUserSolrJson);
    system("nohup $cmd");
    return true;
  }

  public function serialize($object, $format = 'json') {
    return $jsonContent = $this->serializer->serialize($object, $format);
  }
  
  public function deleteCustomGroup($groupCode) {

    $status = false;
    $this->validator->validateGrpCode($groupCode);
    try {
            $groupDaoObj = new GroupDAO();
            $groupDetails = $groupDaoObj->getGrpUsersByGroupCode($groupCode);
            if (!empty($groupDetails)) {
                    $this->removeUsersFromCustomGroup($groupCode);
                    $groupDaoObj = new GroupDAO();
                    $groupDaoObj->deleteSectionGroup($groupCode);
                    $this->deleteSectionGroupFromSolr($groupCode);
                    $status = true;
            } else {
                    throw new GroupException('No Record found with Group Code', 409);
            }
    } catch (\MySQLException $e) {
            $this->logger->error("[GroupComponent:deleteSectionGroup:MySQLException:Message:" . $e->getMessage());
            throw new GroupException($e->getMessage(), 500);
    }
    return $status; 
  }
  
   public function removeUsersFromCustomGroup($groupCode) {
    try {
        $groupDaoObj = new GroupDAO(); 
        $groupModels = $groupDaoObj->selectInactiveUserForSection($groupCode);
    
        foreach($groupModels as $groupModel) {
          foreach($groupModel->getGroupUsers() as $groupUser) {
            if(!empty($groupUser)) {
              foreach($groupUser->getGroupUserRoles() as $groupRoles) {
                if(!empty($groupRoles)) {
                   $roleId = $groupRoles->getId();
                   $groupDaoObj->deleteUserRole($roleId);
                }
              }
                $userId = $groupUser->getId();
                $groupDaoObj->deleteInactiveUserFromSection($userId);
            }
          }
        }
        $status = true;
    } catch (\MySQLException $e) {
        $this->logger->error("[GroupComponent:removeUsersFromCustomGroup:MySQLException:Message:" . $e->getMessage());
        throw new GroupException($e->getMessage(), 500);
    }
    return $status;
  }
  
  public function getAllUserInGroupingForSchoolByRole(\App\HTTP\GetProfileListForUser $searchGroupsSRO, $pageNum, $pageSize, $noPagination = 'true') {
    //print_r($searchGroupsSRO); die;
    $searchDTO = $this->translators->populateUserListDTOFromSRO($searchGroupsSRO);
    return $this->getAllUserInGroupingForSchoolByRoleCommonSearch($searchDTO, $pageNum, $pageSize, $noPagination);
  }
  
  private function getAllUserInGroupingForSchoolByRoleCommonSearch(\App\DTO\GetProfileListForUserDTO $searchCriteriaDTO, $pageNum, $pageSize, $noPagination = 'true') {
  
    if (empty($searchCriteriaDTO->getSchoolCode())) {
      throw new GroupException('School Code is required.', 409);
    }
    if (empty($searchCriteriaDTO->getAyid())) {
      throw new GroupException('Ayid is required.', 409);
    }
    if ($noPagination == 'true') {
      $this->validator->validatePageNumAndPageSize($pageNum, $pageSize);
    }
    try {
      $condition = $this->getPageLimit($pageNum, $pageSize);
      $schoolCode = $searchCriteriaDTO->getSchoolCode();
      $ayId = $searchCriteriaDTO->getAyid();
      $roleCodeArr = (explode("|",$searchCriteriaDTO->getSchoolRoleCode()));
      $roleCode = "'".implode("','",$roleCodeArr)."'";
      $userService = new UserService;
      if ($noPagination == 'true') {
        $groupDaoObj = new GroupDAO();
        $userListDetailsData = $groupDaoObj->getAllUserInGroupFromDb($schoolCode, $ayId, $roleCode, $condition['recordsFrom'], $condition['pageLimit']);
        
        $cnt = count($userListDetailsData);
        
        $counntRecords = count(array_unique(array_column($userListDetailsData, 'uuid')));

        
        $totalPages = ceil($cnt / $pageSize);
        if ($pageNum > $totalPages) {
          throw new GroupException('Records are not present for this Page Number.', 409);
        }
        if ($counntRecords > MAX_LIMIT) {
          throw new GroupException('Please set lower Page Size Limit.', 409);
        }
      } else {
        $pageSize = MAX_LIMIT * 5; //Max limit
        $groupDaoObj = new GroupDAO();
        $userListDetailsData = $groupDaoObj->getAllUserInGroupFromDb($schoolCode, $ayId, $roleCode, $condition['recordsFrom'], $pageSize);
        //$cnt = count($userListDetailsData);
        $cnt = $groupDaoObj->getCountForAllUserInGroupFromDb($schoolCode, $ayId, $roleCode, $condition['recordsFrom'], $pageSize)['total'];
        
        $counntRecords = count(array_unique(array_column($userListDetailsData, 'uuid')));
        
      }
      if ($counntRecords <= 0) {
        throw(new \InvalidArgumentException("Record doesn't exist with given criteria :", 409));
      }
      $usersListUuidsSRORET = array();

        $getGURId = $searchCriteriaDTO->getGetGURId();
        $recordsByUuids = array();
        foreach($userListDetailsData as $records){
          $recordsByUuids[$records['uuid']][] = $records;
        }
        $i=0;
        foreach ($recordsByUuids as $userListDetails) {
          $userListDetailDTO = $this->translators->setDTOByDbOutput($userListDetails, $getGURId);
          
          $usersListUuidsSRO[$i]['groupings'] = $this->translators->populateUserListSROFromDbDTO($userListDetailDTO);
          $usersListUuidsSRO[$i]['schoolCode'] = $userListDetails[0]['school_code'];
          $usersListUuidsSRO[$i]['uuid'] = $userListDetails[0]['uuid'];
          $i++;
        }
      
      $usersListUuidsSRORET['srooutput'] = $usersListUuidsSRO;
      $usersListUuidsSRORET['count'] = $cnt;
    } catch (FlipHTTPException $e) {
      $this->logger->error("Exception occured in userComponent createUser function message: " . $e->getMessage());
      throw new BackendException($e->getMessage(), 500);
    }
    return $usersListUuidsSRORET;
  }
 
	/**
		 * Function getUserClassLevel.
		 * This function is used to get class level of user.
     * @author Deepak Kumar<deepak.kumar@fliplearn.com>
		 */  
  public function getUserClassLevel($getUserClassLevelRequest) {
    $response = null;
    $this->validator->validateGetUserClassLevelRequest($getUserClassLevelRequest);
    try {
      $groupDAO = new GroupDAO();
      $sectionGroupCodes = $groupDAO->getSectionGroupCode($getUserClassLevelRequest->getSchoolCode(),$getUserClassLevelRequest->getAyid(),$getUserClassLevelRequest->getUuid(),$getUserClassLevelRequest->getSchoolRoleCode(),$getUserClassLevelRequest->getGroupTypeCode());
      if(!empty($sectionGroupCodes)) {
        foreach($sectionGroupCodes as $sectionGroupCode) {
          $groupCodesArray[] = $sectionGroupCode['group_code'];
        }
        $groupCodes = implode("|", $groupCodesArray);
        try {
          $request = new \App\External\School\Request\GetClassBySectionGroupCodeRequest;
          $request->setSectionGroupCode($groupCodes);
          $schoolService = new SchoolService;
          $schoolServiceResponse = $schoolService->getClassBySectionGroupCodes($request);
          if(!empty($schoolServiceResponse)) {
            $classes = $schoolServiceResponse['class'];
//            $classLevelIds = array();
//            foreach($classes as $class) {
//              if(!in_array($class['levelId'], $classLevelIds)) {
//                $classLevelIds[] = $class['levelId'];
//              }
//            }
            $response = $classes;
          }
        } catch (FlipHTTPException $e) {
            $this->logger->error("Exception occured in groupComponent:getUserClassLevel function message:Exception in school service call");
            throw new BackendException("Exception in school service call", 500);
        }
      }
    } catch (\SqlException $e) {
      $this->logger->error("GroupComponent:getUserClassLevel:" . $e->getMessage(), 503);
      throw new GroupException($e->getMessage());
    }
    return $response;
  }  
}

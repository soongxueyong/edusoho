<?php

namespace Biz\Classroom\Service\Impl;

use Biz\BaseService;
use Topxia\Common\ArrayToolkit;
use Vip\Service\Vip\VipService;
use Biz\Course\Dao\CourseNoteDao;
use Biz\User\Service\UserService;
use Biz\System\Service\LogService;
use Biz\Classroom\Dao\ClassroomDao;
use Biz\Order\Service\OrderService;
use Biz\User\Service\StatusService;
use Biz\Content\Service\FileService;
use Biz\Taxonomy\Service\TagService;
use Biz\Course\Service\CourseService;
use Biz\Course\Service\MemberService;
use Biz\Task\Service\TaskResultService;
use Codeages\Biz\Framework\Event\Event;
use Biz\Course\Service\CourseSetService;
use Biz\Classroom\Dao\ClassroomCourseDao;
use Biz\Classroom\Dao\ClassroomMemberDao;
use Biz\Taxonomy\Service\CategoryService;
use Biz\Classroom\Service\ClassroomService;

class ClassroomServiceImpl extends BaseService implements ClassroomService
{
    public function searchMembers($conditions, $orderBy, $start, $limit)
    {
        $conditions = $this->_prepareConditions($conditions);
        return $this->getClassroomMemberDao()->search($conditions, $orderBy, $start, $limit);
    }

    public function findClassroomsByIds(array $ids)
    {
        return ArrayToolkit::index($this->getClassroomDao()->findByIds($ids), 'id');
    }

    public function findActiveCoursesByClassroomId($classroomId)
    {
        $classroomCourses = $this->getClassroomCourseDao()->findActiveCoursesByClassroomId($classroomId);
        if (empty($classroomCourses)) {
            return array();
        }

        $courseIds = ArrayToolkit::column($classroomCourses, 'courseId');

        if (empty($courseIds)) {
            return array();
        }

        $courses = $this->getCourseService()->findCoursesByIds($courseIds);

        $courseSetIds = ArrayToolkit::column($classroomCourses, 'courseSetId');
        $courseSets   = $this->getCourseSetService()->findCourseSetsByIds($courseSetIds);
        $courseSets   = ArrayToolkit::index($courseSets, 'id');

        foreach ($courses as &$course) {
            $course['courseSet']         = $courseSets[$course['courseSetId']];
            $course['parentCourseSetId'] = $course['courseSet']['parentId'];
        }

        $sortedCourses = array();
        $courses       = ArrayToolkit::index($courses, 'id');
        foreach ($classroomCourses as $key => $classroomCourse) {
            $sortedCourses[$key]                        = $courses[$classroomCourse['courseId']];
            $sortedCourses[$key]['classroom_course_id'] = $classroomCourse['id'];
        }

        return $sortedCourses;
    }

    public function findMembersByUserIdAndClassroomIds($userId, $classroomIds)
    {
        $members = $this->getClassroomMemberDao()->findByUserIdAndClassroomIds($userId, $classroomIds);
        if (empty($members)) {
            return array();
        }
        return ArrayToolkit::index($members, 'classroomId');
    }

    public function getClassroom($id)
    {
        $classroom = $this->getClassroomDao()->get($id);

        return $classroom;
    }

    public function searchClassrooms($conditions, $orderBy, $start, $limit)
    {
        $conditions = $this->_prepareClassroomConditions($conditions);

        return $this->getClassroomDao()->search($conditions, $orderBy, $start, $limit);
    }

    public function searchClassroomsCount($conditions)
    {
        $conditions = $this->_prepareClassroomConditions($conditions);
        $count      = $this->getClassroomDao()->count($conditions);

        return $count;
    }

    public function findClassroomIdsByCourseId($courseId)
    {
        return $this->getClassroomCourseDao()->findClassroomIdsByCourseId($courseId);
    }

    /**
     * @deprecated
     * @param  int     $courseId
     * @return array
     */
    public function findClassroomsByCourseId($courseId)
    {
        $classroomIds = $this->findClassroomIdsByCourseId($courseId);
        return $this->findClassroomsByIds($classroomIds);
    }

    public function getClassroomByCourseId($courseId)
    {
        $classroomIds = $this->findClassroomIdsByCourseId($courseId);
        if (empty($classroomIds)) {
            return array();
        }
        $classroomId = array_shift($classroomIds);

        return $this->getClassroom($classroomId['classroomId']);
    }

    public function findAssistants($classroomId)
    {
        $classroom  = $this->getClassroom($classroomId);
        $assistants = $this->getClassroomMemberDao()->findAssistantsByClassroomId($classroomId);

        if (!$assistants) {
            return array();
        }

        $assistantIds    = ArrayToolkit::column($assistants, 'userId');
        $oldAssistantIds = $classroom['assistantIds'] ?: array();

        if (!empty($oldAssistantIds)) {
            $orderAssistantIds = array_intersect($oldAssistantIds, $assistantIds);
            $orderAssistantIds = array_merge($orderAssistantIds, array_diff($assistantIds, $oldAssistantIds));
        } else {
            $orderAssistantIds = $assistantIds;
        }

        return $orderAssistantIds;
    }

    public function findTeachers($classroomId)
    {
        $teachers = $this->getClassroomMemberDao()->findTeachersByClassroomId($classroomId);

        if (!$teachers) {
            return array();
        }

        $classroom     = $this->getClassroom($classroomId);
        $teacherIds    = ArrayToolkit::column($teachers, 'userId');
        $oldTeacherIds = $classroom['teacherIds'] ?: array();

        if (!empty($oldTeacherIds)) {
            $orderTeacherIds = array_intersect($oldTeacherIds, $teacherIds);
            $orderTeacherIds = array_merge($orderTeacherIds, array_diff($teacherIds, $oldTeacherIds));
        } else {
            $orderTeacherIds = $teacherIds;
        }

        return $orderTeacherIds;
    }

    public function addClassroom($classroom)
    {
        $title = trim($classroom['title']);

        if (empty($title)) {
            throw $this->createServiceException('班级名称不能为空！');
        }

        $classroom = $this->fillOrgId($classroom);

        $classroom = $this->getClassroomDao()->create($classroom);
        $this->dispatchEvent("classroom.create", $classroom);
        $this->getLogService()->info('classroom', 'create', "创建班级《{$classroom['title']}》(#{$classroom['id']})");

        return $classroom;
    }

    public function addCoursesToClassroom($classroomId, $courseIds)
    {
        $this->tryManageClassroom($classroomId);
        $this->beginTransaction();
        try {
            $allExistingCourses = $this->findCoursesByClassroomId($classroomId);

            $existCourseIds = ArrayToolkit::column($allExistingCourses, 'parentId');

            $diff      = array_diff($courseIds, $existCourseIds);
            $classroom = $this->getClassroom($classroomId);

            if (!empty($diff)) {
                $courses      = $this->getCourseService()->findCoursesByIds($diff);
                $newCourseIds = array();

                foreach ($courses as $key => $course) {
                    $newCourse      = $this->getCourseSetService()->copyCourseSet($classroomId, $course['courseSetId'], $course['id']);
                    $newCourseIds[] = $newCourse['id'];
                    $this->getLogService()->info('classroom', 'add_course', "班级《{$classroom['title']}》(#{$classroom['id']})添加了课程《{$newCourse['title']}》(#{$newCourse['id']})");
                }

                $this->setClassroomCourses($classroomId, $newCourseIds);
            }

            $courses    = $this->findActiveCoursesByClassroomId($classroomId);
            $coursesNum = count($courses);

            $lessonNum = 0;

            foreach ($courses as $key => $course) {
                $lessonNum += $course['taskNum'];
            }

            $this->updateClassroom($classroomId, array("courseNum" => $coursesNum, "lessonNum" => $lessonNum));

            $this->updateClassroomTeachers($classroomId);

            $this->refreshCoursesSeq($classroomId, $courseIds);

            $this->commit();

            return $courses;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function findClassroomByTitle($title)
    {
        return $this->getClassroomDao()->getByTitle($title);
    }

    public function findClassroomsByLikeTitle($title)
    {
        return $this->getClassroomDao()->findByLikeTitle($title);
    }

    public function updateClassroom($id, $fields)
    {
        $user = $this->getCurrentUser();

        if (isset($fields['tagIds'])) {
            $tagIds = empty($fields['tagIds']) ? array() : $fields['tagIds'];
        }

        $fields = ArrayToolkit::parts($fields, array('rating', 'ratingNum', 'categoryId', 'title', 'status', 'about', 'description', 'price', 'vipLevelId', 'smallPicture', 'middlePicture', 'largePicture', 'headTeacherId', 'teacherIds', 'assistantIds', 'hitNum', 'auditorNum', 'studentNum', 'courseNum', 'lessonNum', 'threadNum', 'postNum', 'income', 'createdTime', 'private', 'service', 'maxRate', 'buyable', 'showable', 'orgCode', 'orgId'));

        if (empty($fields)) {
            throw $this->createInvalidArgumentException('参数不正确，更新失败！');
        }

        $fields = $this->fillOrgId($fields);

        $classroom = $this->getClassroomDao()->update($id, $fields);

        if (isset($tagIds)) {
            $this->dispatchEvent('classroom.update', new Event(array('userId' => $user['id'], 'classroomId' => $id, 'tagIds' => $tagIds)));
        } else {
            $this->dispatchEvent('classroom.update', new Event(array('userId' => $user['id'], 'classroomId' => $id)));
        }

        return $classroom;
    }

    public function batchUpdateOrg($classroomIds, $orgCode)
    {
        if (!is_array($classroomIds)) {
            $classroomIds = array($classroomIds);
        }
        $fields = $this->fillOrgId(array('orgCode' => $orgCode));

        foreach ($classroomIds as $classroomId) {
            $this->getClassroomDao()->update($classroomId, $fields);
        }
    }

    public function waveClassroom($id, $field, $diff)
    {
        return $this->getClassroomDao()->wave(array($id), array($field => $diff));
    }

    private function deleteAllCoursesInClass($id)
    {
        $courses   = $this->findCoursesByClassroomId($id);
        $courseIds = ArrayToolkit::column($courses, 'id');

        $this->deleteClassroomCourses($id, $courseIds);
    }

    public function deleteClassroom($id)
    {
        $classroom = $this->getClassroom($id);

        if (empty($classroom)) {
            throw $this->createServiceException('班级不存在，操作失败。');
        }

        if ($classroom['status'] != 'draft') {
            throw $this->createServiceException('只有未发布班级可以删除，操作失败。');
        }

        $this->tryManageClassroom($id, 'admin_classroom_delete');

        $this->deleteAllCoursesInClass($id);
        $this->getClassroomDao()->delete($id);
        $this->getLogService()->info('Classroom', 'delete', "班级#{$id}永久删除");

        $this->dispatchEvent("classroom.delete", $classroom);

        return true;
    }

    /**
     * @todo 能否简化业务逻辑？
     */
    public function updateClassroomTeachers($id)
    {
        $courses = $this->findActiveCoursesByClassroomId($id);

        $oldTeacherIds = $this->findTeachers($id);
        $newTeacherIds = array();

        foreach ($courses as $key => $value) {
            $teachers      = $this->getCourseMemberService()->findCourseTeachers($value['id']);
            $teacherIds    = ArrayToolkit::column($teachers, 'userId');
            $newTeacherIds = array_merge($newTeacherIds, $teacherIds);
        }

        $newTeacherIds = array_unique($newTeacherIds);
        $ids           = array();

        foreach ($newTeacherIds as $key => $value) {
            $ids[] = $value;
        }

        $newTeacherIds    = $ids;
        $deleteTeacherIds = array_diff($oldTeacherIds, $newTeacherIds);
        $addTeacherIds    = array_diff($newTeacherIds, $oldTeacherIds);
        $addMembers       = $this->findMembersByClassroomIdAndUserIds($id, $addTeacherIds);
        $deleteMembers    = $this->findMembersByClassroomIdAndUserIds($id, $deleteTeacherIds);

        foreach ($addTeacherIds as $userId) {
            if (!empty($addMembers[$userId])) {
                if ($addMembers[$userId]['role'][0] == 'auditor') {
                    $addMembers[$userId]['role'][0] = 'teacher';
                } else {
                    $addMembers[$userId]['role'][] = 'teacher';
                }

                $this->getClassroomMemberDao()->update($addMembers[$userId]['id'], $addMembers[$userId]);
            } else {
                $this->becomeTeacher($id, $userId);
            }
        }

        foreach ($deleteTeacherIds as $userId) {
            if (count($deleteMembers[$userId]['role']) == 1) {
                $this->getClassroomMemberDao()->delete($deleteMembers[$userId]['id']);
            } else {
                foreach ($deleteMembers[$userId]['role'] as $key => $value) {
                    if ($value == 'teacher') {
                        unset($deleteMembers[$userId]['role'][$key]);
                    }
                }

                $this->getClassroomMemberDao()->update($deleteMembers[$userId]['id'], $deleteMembers[$userId]);
            }
        }
    }

    public function publishClassroom($id)
    {
        $this->tryManageClassroom($id, 'admin_classroom_open');

        $this->updateClassroom($id, array("status" => "published"));
    }

    public function closeClassroom($id)
    {
        $this->tryManageClassroom($id, 'admin_classroom_close');

        $this->updateClassroom($id, array("status" => "closed"));
    }

    public function changePicture($id, $data)
    {
        $classroom = $this->getClassroomDao()->get($id);

        if (empty($classroom)) {
            throw $this->createServiceException('班级不存在，图标更新失败！');
        }

        $fileIds = ArrayToolkit::column($data, "id");
        $files   = $this->getFileService()->getFilesByIds($fileIds);

        $files   = ArrayToolkit::index($files, "id");
        $fileIds = ArrayToolkit::index($data, "type");

        $fields = array(
            'smallPicture'  => $files[$fileIds["small"]["id"]]["uri"],
            'middlePicture' => $files[$fileIds["middle"]["id"]]["uri"],
            'largePicture'  => $files[$fileIds["large"]["id"]]["uri"]
        );

        $this->deleteNotUsedPictures($classroom);

        $this->getLogService()->info('classroom', 'update_picture', "更新课程《{$classroom['title']}》(#{$classroom['id']})图片", $fields);

        return $this->updateClassroom($id, $fields);
    }

    private function deleteNotUsedPictures($classroom)
    {
        $oldPictures = array(
            'smallPicture'  => $classroom['smallPicture'] ? $classroom['smallPicture'] : null,
            'middlePicture' => $classroom['middlePicture'] ? $classroom['middlePicture'] : null,
            'largePicture'  => $classroom['largePicture'] ? $classroom['largePicture'] : null
        );

        $self = $this;
        array_map(function ($oldPicture) use ($self) {
            if (!empty($oldPicture)) {
                $self->getFileService()->deleteFileByUri($oldPicture);
            }
        }, $oldPictures);
    }

    public function isCourseInClassroom($courseId, $classroomId)
    {
        $classroomCourse = $this->getClassroomCourseDao()->getByClassroomIdAndCourseId($classroomId, $courseId);

        return empty($classroomCourse) ? false : true;
    }

    public function setClassroomCourses($classroomId, array $courseIds)
    {
        $courses        = $this->findCoursesByClassroomId($classroomId);
        $existCourseIds = ArrayToolkit::column($courses, 'id');
        foreach ($courseIds as $key => $value) {
            if (!(in_array($value, $existCourseIds))) {
                $this->addCourse($classroomId, $value);
            }
        }
    }

    public function deleteClassroomCourses($classroomId, array $courseIds)
    {
        foreach ($courseIds as $key => $value) {
            $this->getClassroomCourseDao()->deleteByClassroomIdAndCourseId($classroomId, $value);
        }
    }

    public function countMobileVerifiedMembersByClassroomId($classroomId, $locked = 0)
    {
        return $this->getClassroomMemberDao()->countMobileVerifiedMembersByClassroomId($classroomId, $locked);
    }

    public function searchMemberCount($conditions)
    {
        $conditions = $this->_prepareClassroomConditions($conditions);

        return $this->getClassroomMemberDao()->count($conditions);
    }

    public function findMemberUserIdsByClassroomId($classroomId)
    {
        return $this->getClassroomMemberDao()->findMemberIdsByClassroomId($classroomId);
    }

    public function getClassroomMember($classroomId, $userId)
    {
        $member = $this->getClassroomMemberDao()->getByClassroomIdAndUserId($classroomId, $userId);
        return !$member ? null : $member;
    }

    public function remarkStudent($classroomId, $userId, $remark)
    {
        $member = $this->getClassroomMember($classroomId, $userId);

        if (empty($member)) {
            throw $this->createServiceException('学员不存在，备注失败!');
        }

        $fields = array('remark' => empty($remark) ? '' : (string) $remark);

        return $this->getClassroomMemberDao()->update($member['id'], $fields);
    }

    public function removeStudent($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createServiceException('班级不存在，操作失败。');
        }

        $member = $this->getClassroomMember($classroomId, $userId);

        if (empty($member) || !(array_intersect($member['role'], array('student', 'auditor')))) {
            throw $this->createServiceException("用户(#{$userId})不是班级(#{$classroomId})的学员，退出班级失败。");
        }

        $this->removeStudentsFromClasroomCourses($classroomId, $userId);

        if (count($member['role']) == 1) {
            $this->getClassroomMemberDao()->delete($member['id']);
        } else {
            foreach ($member['role'] as $key => $value) {
                if ($value == 'student') {
                    unset($member['role'][$key]);
                }
            }

            $this->getClassroomMemberDao()->update($member['id'], $member);
        }

        $classroom = $this->updateStudentNumAndAuditorNum($classroomId);
        $user      = $this->getUserService()->getUser($member['userId']);

        $this->getLogService()->info('classroom', 'remove_student', "班级《{$classroom['title']}》(#{$classroom['id']})，移除学员{$user['nickname']}(#{$user['id']})");
        $this->dispatchEvent(
            'classroom.quit',
            new Event($classroom, array('userId' => $member['userId'], 'member' => $member))
        );
    }

    public function isClassroomStudent($classroomId, $userId)
    {
        $member = $this->getClassroomMember($classroomId, $userId);
        return (empty($member) || !in_array('student', $member['role'])) ? false : true;
    }

    public function isClassroomAssistant($classroomId, $userId)
    {
        $member = $this->getClassroomMember($classroomId, $userId);
        return (empty($member) || !in_array('assistant', $member['role'])) ? false : true;
    }

    public function isClassroomTeacher($classroomId, $userId)
    {
        $member = $this->getClassroomMember($classroomId, $userId);
        return (empty($member) || !in_array('teacher', $member['role'])) ? false : true;
    }

    public function isClassroomHeadTeacher($classroomId, $userId)
    {
        $member = $this->getClassroomMember($classroomId, $userId);
        return (empty($member) || !in_array('headTeacher', $member['role'])) ? false : true;
    }

    // becomeStudent的逻辑条件，写注释
    public function becomeStudent($classroomId, $userId, $info = array())
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException();
        }

        if (!in_array($classroom['status'], array('published', 'closed'))) {
            throw $this->createServiceException('不能加入未发布班级');
        }

        $user = $this->getUserService()->getUser($userId);

        if (empty($user)) {
            throw $this->createServiceException("用户(#{$userId})不存在，加入班级失败！");
        }

        $member = $this->getClassroomMember($classroomId, $userId);

        if (!$this->canBecomeClassroomMember($member)) {
            throw $this->createServiceException("该用户(#{$userId})不能成为该班级学员！");
        }

        $userMember = array();

        if (!empty($info['becomeUseMember'])) {
            $levelChecked = $this->getVipService()->checkUserInMemberLevel($user['id'], $classroom['vipLevelId']);

            if ($levelChecked != 'ok') {
                throw $this->createServiceException("用户(#{$userId})不能以会员身份加入班级！");
            }

            $userMember = $this->getVipService()->getMemberByUserId($user['id']);
        }

        if (!empty($info['orderId'])) {
            $order = $this->getOrderService()->getOrder($info['orderId']);

            if (empty($order)) {
                throw $this->createServiceException("订单(#{$info['orderId']})不存在，加入班级失败！");
            }
        } else {
            $order = null;
        }

        $fields = array(
            'classroomId' => $classroomId,
            'userId'      => $userId,
            'orderId'     => empty($order) ? 0 : $order['id'],
            'levelId'     => empty($info['becomeUseMember']) ? 0 : $userMember['levelId'],
            'role'        => array('student'),
            'remark'      => empty($order['note']) ? '' : $order['note']
        );

        if (empty($fields['remark'])) {
            $fields['remark'] = empty($info['note']) ? '' : $info['note'];
        }

        if (!empty($member)) {
            if ($member['role'][0] != 'auditor') {
                $member['role'][]  = 'student';
                $member['orderId'] = $fields['orderId'];
                $member['levelId'] = $fields['levelId'];
                $member['remark']  = $fields['remark'];
            } else {
                $member['role']    = array('student');
                $member['orderId'] = $fields['orderId'];
            }

            $member = $this->getClassroomMemberDao()->update($member['id'], $member);
        } else {
            $member = $this->getClassroomMemberDao()->create($fields);
        }

        $courses = $this->getClassroomCourseDao()->findActiveCoursesByClassroomId($classroomId);

        $courseIds = ArrayToolkit::column($courses, "courseId");

        foreach ($courseIds as $key => $courseId) {
            $courseMember = $this->getCourseMemberService()->getCourseMember($courseId, $userId);

            if (empty($courseMember)) {
                $info = array(
                    'orderId'   => empty($order) ? 0 : $order['id'],
                    'orderNote' => empty($order['note']) ? '' : $order['note'],
                    'levelId'   => empty($member['levelId']) ? 0 : $member['levelId']
                );
                $this->getCourseMemberService()->createMemberByClassroomJoined($courseId, $userId, $classroomId, $info);
            }
        }

        $fields = array(
            'studentNum' => $this->getClassroomStudentCount($classroomId),
            'auditorNum' => $this->getClassroomAuditorCount($classroomId)
        );

        if ($order) {
            $income           = $this->getOrderService()->sumOrderPriceByTarget('classroom', $classroomId);
            $fields['income'] = empty($income) ? 0 : $income;
        }

        $this->getClassroomDao()->update($classroomId, $fields);
        $this->dispatchEvent(
            'classroom.join',
            new Event($classroom, array('userId' => $member['userId'], 'member' => $member))
        );

        return $member;
    }

    public function updateClassroomCourses($classroomId, $activeCourseIds)
    {
        $this->tryManageClassroom($classroomId);
        $this->beginTransaction();
        try {
            $courses        = $this->findActiveCoursesByClassroomId($classroomId);
            $courses        = ArrayToolkit::index($courses, 'id');
            $existCourseIds = ArrayToolkit::column($courses, 'id');

            $diff      = array_diff($existCourseIds, $activeCourseIds);
            $classroom = $this->getClassroom($classroomId);
            if (!empty($diff)) {
                foreach ($diff as $courseId) {
                    $this->getCourseService()->updateCourse($courseId, array('locked' => 0));
                    $this->getCourseService()->closeCourse($courseId); //, 'classroom'

                    $this->getClassroomCourseDao()->deleteByClassroomIdAndCourseId($classroomId, $courseId);
                    $this->getCourseMemberService()->deleteMemberByCourseIdAndRole($courseId, 'student');

                    $course = $this->getCourseService()->getCourse($courseId);
                    $this->getClassroomDao()->wave(array($classroomId), array('noteNum' => "-{$course['noteNum']}"));
                    $this->getLogService()->info('classroom', 'delete_course', "班级《{$classroom['title']}》(#{$classroom['id']})删除了课程《{$course['title']}》(#{$course['id']})");
                }

                $courses    = $this->findActiveCoursesByClassroomId($classroomId);
                $coursesNum = count($courses);

                $lessonNum = 0;

                foreach ($courses as $key => $course) {
                    $lessonNum += $course['taskNum'];
                }

                $this->updateClassroom($classroomId, array("courseNum" => $coursesNum, "lessonNum" => $lessonNum));

                $this->updateClassroomTeachers($classroomId);
            }

            $this->refreshCoursesSeq($classroomId, $activeCourseIds);

            $this->commit();

            $this->dispatchEvent(
                'classroom.course.delete',
                new Event($activeCourseIds, array('classroomId' => $classroomId))
            );
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function findClassroomsByCoursesIds($courseIds)
    {
        return $this->getClassroomCourseDao()->findByCoursesIds($courseIds);
    }

    private function refreshCoursesSeq($classroomId, $courseIds)
    {
        $seq = 1;

        foreach ($courseIds as $key => $courseId) {
            $classroomCourse = $this->getClassroomCourse($classroomId, $courseId);
            $this->getClassroomCourseDao()->update($classroomCourse['id'], array('seq' => $seq));
            $seq++;
        }
    }

    public function getClassroomCourse($classroomId, $courseId)
    {
        return $this->getClassroomCourseDao()->getByClassroomIdAndCourseId($classroomId, $courseId);
    }

    public function findCoursesByClassroomId($classroomId)
    {
        $classroomCourses = $this->getClassroomCourseDao()->findByClassroomId($classroomId);
        $courseIds        = ArrayToolkit::column($classroomCourses, "courseId");
        $courses          = $this->getCourseService()->findCoursesByIds($courseIds);
        $courses          = ArrayToolkit::index($courses, "id");
        $sordtedCourses   = array();

        foreach ($classroomCourses as $key => $classroomCourse) {
            $sordtedCourses[$key] = $courses[$classroomCourse["courseId"]];
        }

        return $sordtedCourses;
    }

    public function getClassroomStudentCount($classroomId)
    {
        return $this->getClassroomMemberDao()->countStudents($classroomId);
    }

    public function getClassroomAuditorCount($classroomId)
    {
        return $this->getClassroomMemberDao()->countAuditors($classroomId);
    }

    public function addHeadTeacher($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if ($classroom['headTeacherId']) {
            if ($userId == $classroom['headTeacherId']) {
                return;
            }

            $headTeacherMember = $this->getClassroomMember($classroomId, $classroom['headTeacherId']);

            if (count($headTeacherMember['role']) == 1) {
                $this->getClassroomMemberDao()->deleteByClassroomIdAndUserId($classroomId, $classroom['headTeacherId']);
            } else {
                foreach ($headTeacherMember['role'] as $key => $value) {
                    if ($value == 'headTeacher') {
                        unset($headTeacherMember['role'][$key]);
                    }
                }

                $this->getClassroomMemberDao()->update($headTeacherMember['id'], $headTeacherMember);
            }
        }

        if (!empty($userId)) {
            $this->updateClassroom($classroomId, array('headTeacherId' => $userId));

            $member = $this->getClassroomMember($classroomId, $userId);

            if ($member) {
                if ($member['role'][0] == 'auditor') {
                    $member['role'][0] = 'headTeacher';
                } else {
                    $member['role'][] = 'headTeacher';
                }

                $this->getClassroomMemberDao()->update($member['id'], $member);
            } else {
                $fields = array(
                    'classroomId' => $classroomId,
                    'userId'      => $userId,
                    'orderId'     => 0,
                    'levelId'     => 0,
                    'role'        => array('headTeacher'),
                    'remark'      => '',
                    'createdTime' => time()
                );
                $this->getClassroomMemberDao()->create($fields);
            }

            $this->dispatchEvent('classMaster.become', new Event($member));
        }
    }

    public function updateAssistants($classroomId, $userIds)
    {
        $assistantIds       = $this->findAssistants($classroomId);
        $deleteAssistantIds = array_diff($assistantIds, $userIds);
        $addAssistantIds    = array_diff($userIds, $assistantIds);
        $addMembers         = $this->findMembersByClassroomIdAndUserIds($classroomId, $addAssistantIds);
        $deleteMembers      = $this->findMembersByClassroomIdAndUserIds($classroomId, $deleteAssistantIds);

        foreach ($addAssistantIds as $userId) {
            if (!empty($addMembers[$userId])) {
                if ($addMembers[$userId]['role'][0] == 'auditor') {
                    $addMembers[$userId]['role'][0] = 'assistant';
                } else {
                    $addMembers[$userId]['role'][] = 'assistant';
                }

                $this->getClassroomMemberDao()->update($addMembers[$userId]['id'], $addMembers[$userId]);
            } else {
                $this->becomeAssistant($classroomId, $userId);
            }
        }

        foreach ($deleteAssistantIds as $userId) {
            if (count($deleteMembers[$userId]['role']) == 1) {
                $this->getClassroomMemberDao()->delete($deleteMembers[$userId]['id']);
            } else {
                foreach ($deleteMembers[$userId]['role'] as $key => $value) {
                    if ($value == 'assistant') {
                        unset($deleteMembers[$userId]['role'][$key]);
                    }
                }

                $this->getClassroomMemberDao()->update($deleteMembers[$userId]['id'], $deleteMembers[$userId]);
            }
        }
    }

    public function becomeAuditor($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException();
        }

        if ($classroom['status'] != 'published') {
            throw $this->createServiceException('不能加入未发布班级');
        }

        $user = $this->getUserService()->getUser($userId);

        if (empty($user)) {
            throw $this->createServiceException("用户(#{$userId})不存在，加入班级失败！");
        }

        $member = $this->getClassroomMember($classroomId, $userId);

        if (!$this->canBecomeClassroomMember($member)) {
            throw $this->createServiceException("该用户(#{$userId})不能成为该班级的旁听生！");
        }

        $fields = array(
            'classroomId' => $classroomId,
            'userId'      => $userId,
            'orderId'     => 0,
            'levelId'     => 0,
            'role'        => array('auditor'),
            'remark'      => '',
            'createdTime' => time()
        );

        $member = $this->getClassroomMemberDao()->create($fields);

        $classroom = $this->updateStudentNumAndAuditorNum($classroomId);
        $this->dispatchEvent(
            'classroom.auditor_join',
            new Event($classroom, array('userId' => $member['userId']))
        );

        return $member;
    }

    public function becomeAssistant($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUserService()->getUser($userId);

        if (empty($user)) {
            throw $this->createServiceException("用户(#{$userId})不存在，加入班级失败！");
        }

        $fields = array(
            'classroomId' => $classroomId,
            'userId'      => $userId,
            'orderId'     => 0,
            'levelId'     => 0,
            'role'        => array('assistant'),
            'remark'      => '',
            'createdTime' => time()
        );

        $member = $this->getClassroomMemberDao()->create($fields);

        $this->dispatchEvent(
            'classroom.become_assistant',
            new Event($classroom, array('userId' => $member['userId']))
        );

        return $member;
    }

    public function becomeTeacher($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUserService()->getUser($userId);

        if (empty($user)) {
            throw $this->createServiceException("用户(#{$userId})不存在，加入班级失败！");
        }

        $fields = array(
            'classroomId' => $classroomId,
            'userId'      => $userId,
            'orderId'     => 0,
            'levelId'     => 0,
            'role'        => array('teacher'),
            'remark'      => '',
            'createdTime' => time()
        );

        $member = $this->getClassroomMemberDao()->create($fields);

        $this->dispatchEvent(
            'classroom.become_teacher',
            new Event($classroom, array('userId' => $member['userId']))
        );

        return $member;
    }

    public function isClassroomAuditor($classroomId, $studentId)
    {
        $member = $this->getClassroomMember($classroomId, $studentId);

        if ($member) {
            if (in_array('auditor', $member['role'])) {
                return true;
            }
        }

        return false;
    }

    protected function _prepareClassroomConditions($conditions)
    {
        $conditions = array_filter($conditions, function ($value) {
            if ($value === 0 || !empty($value)) {
                return true;
            } else {
                return false;
            }
        });

        if (isset($conditions['nickname'])) {
            $user                 = $this->getUserService()->getUserByNickname($conditions['nickname']);
            $conditions['userId'] = $user ? $user['id'] : -1;
            unset($conditions['nickname']);
        }

        if (isset($conditions['categoryId'])) {
            $childrenIds               = $this->getCategoryService()->findCategoryChildrenIds($conditions['categoryId']);
            $conditions['categoryIds'] = array_merge(array($conditions['categoryId']), $childrenIds);
            unset($conditions['categoryId']);
        }

        return $conditions;
    }

    private function canBecomeClassroomMember($member)
    {
        return empty($member) || !in_array('student', $member['role']);
    }

    /**
     * @param  $id
     * @param  $permission
     * @return bool
     */
    public function canManageClassroom($id, $permission = 'admin_classroom_content_manage')
    {
        $classroom = $this->getClassroom($id);

        if (empty($classroom)) {
            return false;
        }

        $user = $this->getCurrentUser();
        if (!$user->isLogin()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->hasPermission($permission)) {
            return true;
        }

        $member = $this->getClassroomMember($id, $user['id']);

        if (empty($member)) {
            return false;
        }

        if (array_intersect($member['role'], array('headTeacher'))) {
            return true;
        }

        return false;
    }

    public function tryManageClassroom($id, $actionPermission = null)
    {
        if (!$this->canManageClassroom($id, $actionPermission)) {
            // throw $this->createAccessDeniedException('您无权操作！');
        }
    }

    public function canTakeClassroom($id, $includeAuditor = false)
    {
        $classroom = $this->getClassroom($id);

        if (empty($classroom)) {
            return false;
        }

        $user = $this->getCurrentUser();

        if (!$user->isLogin()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $member = $this->getClassroomMember($id, $user['id']);

        if (empty($member)) {
            return false;
        }

        if (array_intersect($member['role'], array('student', 'assistant', 'teacher', 'headTeacher'))) {
            return true;
        }

        if ($includeAuditor && in_array('auditor', $member['role'])) {
            return true;
        }

        return false;
    }

    public function tryTakeClassroom($id, $includeAuditor = false)
    {
        if (!$this->canTakeClassroom($id, $includeAuditor)) {
            throw $this->createAccessDeniedException('您无权操作！');
        }
    }

    public function canHandleClassroom($id)
    {
        $classroom = $this->getClassroom($id);

        if (empty($classroom)) {
            return false;
        }

        $user = $this->getCurrentUser();

        if (!$user->isLogin()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $member = $this->getClassroomMember($id, $user['id']);

        if (empty($member)) {
            return false;
        }

        if (array_intersect($member['role'], array('assistant', 'teacher', 'headTeacher'))) {
            return true;
        }

        return false;
    }

    public function tryHandleClassroom($id)
    {
        if (!$this->canHandleClassroom($id)) {
            throw $this->createAccessDeniedException('您无权操作！');
        }
    }

    public function canLookClassroom($id)
    {
        $classroom = $this->getClassroom($id);

        if (empty($classroom)) {
            return false;
        }

        $user = $this->getCurrentUser();

        if (!$user->isLogin() && $classroom['showable']) {
            return true;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $member = $this->getClassroomMember($id, $user['id']);

        if (empty($member) && $classroom['showable']) {
            return true;
        }

        if ($member) {
            return true;
        }

        return false;
    }

    public function tryLookClassroom($id)
    {
        if (!$this->canLookClassroom($id)) {
            throw $this->createAccessDeniedException('您无权操作！');
        }
    }

    public function canCreateThreadEvent($resource)
    {
        $classroomId = $resource['targetId'];
        $user        = $this->getCurrentUser();
        $classroom   = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            return false;
        }

        if (!$user->isLogin()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $member = $this->getClassroomMember($classroomId, $user['id']);

        if (empty($member)) {
            return false;
        }

        return array_intersect($member['role'], array('teacher', 'headTeacher', 'assistant'));
    }

    // @todo 写逻辑条件的注释
    public function exitClassroom($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException();
        }

        $member = $this->getClassroomMember($classroomId, $userId);

        if (!$member) {
            throw $this->createAccessDeniedException('您不是班级学员，无法退出班级！');
        }

        if (!array_intersect($member['role'], array('student', 'auditor'))) {
            throw $this->createAccessDeniedException('教师无法退出班级！');
        }

        $this->removeStudentsFromClasroomCourses($classroomId, $userId);

        if (count($member['role']) == 1) {
            $this->getClassroomMemberDao()->deleteByClassroomIdAndUserId($classroomId, $userId);
        } else {
            foreach ($member['role'] as $key => $value) {
                if ($value == 'student') {
                    unset($member['role'][$key]);
                }
            }

            $this->getClassroomMemberDao()->update($member['id'], $member);
        }

        $this->updateStudentNumAndAuditorNum($classroomId);

        $this->dispatchEvent(
            'classroom.quit',
            new Event($classroom, array('userId' => $userId, 'member' => $member))
        );
    }

    private function removeStudentsFromClasroomCourses($classroomId, $userId)
    {
        $classroomCourses = $this->getClassroomCourseDao()->findActiveCoursesByClassroomId($classroomId);

        $courseIds = ArrayToolkit::column($classroomCourses, 'courseId');

        foreach ($courseIds as $key => $courseId) {
            $count        = 0;
            $courseMember = $this->getCourseMemberService()->getCourseMember($courseId, $userId);

            if ($courseMember && $courseMember['joinedType'] == "course") {
                unset($courseIds[$key]);
                continue;
            }

            $classroomIds = $this->getClassroomCourseDao()->findClassroomIdsByCourseId($courseId);
            $classroomIds = ArrayToolkit::column($classroomIds, "classroomId");

            foreach ($classroomIds as $value) {
                if ($classroomId == $value) {
                    continue;
                }

                $member = $this->getClassroomMember($value, $userId);

                if ($member) {
                    $count = 1;
                    break;
                }
            }

            if ($count == 1) {
                unset($courseIds[$key]);
            }
        }

        foreach ($courseIds as $key => $value) {
            if ($this->getCourseMemberService()->isCourseStudent($value, $userId)) {
                $this->getCourseMemberService()->removeStudent($value, $userId);
            }
        }
    }

    protected function isHeadTeacher($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if ($classroom['headTeacherId'] == $userId) {
            return true;
        }

        return false;
    }

    public function findClassroomStudents($classroomId, $start, $limit)
    {
        return $this->getClassroomMemberDao()->findByClassroomIdAndRole($classroomId, 'student', $start, $limit);
    }

    public function findClassroomMembersByRole($classroomId, $role, $start, $limit)
    {
        $members = $this->getClassroomMemberDao()->findByClassroomIdAndRole($classroomId, $role, $start, $limit);
        return !$members ? array() : ArrayToolkit::index($members, 'userId');
    }

    public function findMembersByClassroomIdAndUserIds($classroomId, $userIds)
    {
        $members = $this->getClassroomMemberDao()->findByClassroomIdAndUserIds($classroomId, $userIds);

        return !$members ? array() : ArrayToolkit::index($members, 'userId');
    }

    public function lockStudent($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException("班级(#{$classroomId})不存在，封锁学员失败。");
        }

        $member = $this->getClassroomMember($classroomId, $userId);

        if (empty($member) || !in_array('student', $member['role'])) {
            throw $this->createServiceException("用户(#{$classroomId})不是班级(#{$classroomId})的学员，封锁学员失败。");
        }

        if ($member['locked']) {
            return;
        }

        $this->getClassroomMemberDao()->update($member['id'], array('locked' => 1));
    }

    public function unlockStudent($classroomId, $userId)
    {
        $classroom = $this->getClassroom($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException("班级(#{$classroomId})不存在，封锁学员失败。");
        }

        $member = $this->getClassroomMember($classroomId, $userId);

        if (empty($member) || !in_array('student', $member['role'])) {
            throw $this->createServiceException("用户(#{$userId})不是该班级(#{$classroomId})的学员，解封学员失败。");
        }

        if (empty($member['locked'])) {
            return;
        }

        $this->getClassroomMemberDao()->update($member['id'], array('locked' => 0));
    }

    public function recommendClassroom($id, $number)
    {
        $this->tryAdminClassroom($id);

        if (!is_numeric($number)) {
            throw $this->createAccessDeniedException('推荐班级序号只能为数字！');
        }

        $classroom = $this->getClassroomDao()->update($id, array(
            'recommended'     => 1,
            'recommendedSeq'  => (int) $number,
            'recommendedTime' => time()
        ));

        $this->getLogService()->info('classroom', 'recommend', "推荐班级《{$classroom['title']}》(#{$classroom['id']}),序号为{$number}");

        return $classroom;
    }

    public function cancelRecommendClassroom($id)
    {
        $this->tryAdminClassroom($id);

        $classroom = $this->getClassroomDao()->update($id, array(
            'recommended'     => 0,
            'recommendedTime' => 0,
            'recommendedSeq'  => 100
        ));

        $this->getLogService()->info('classroom', 'cancel_recommend', "取消推荐班级《{$classroom['title']}》(#{$classroom['id']})");

        return $classroom;
    }

    public function tryAdminClassroom($classroomId)
    {
        $classroom = $this->getClassroomDao()->get($classroomId);

        if (empty($classroom)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getCurrentUser();

        if (empty($user->id)) {
            throw $this->createAccessDeniedException('未登录用户，无权操作！');
        }

        if (count(array_intersect($user['roles'], array('ROLE_ADMIN', 'ROLE_SUPER_ADMIN'))) == 0) {
            throw $this->createAccessDeniedException('您不是管理员，无权操作！');
        }

        return $classroom;
    }

    public function getClassroomMembersByCourseId($courseId, $userId)
    {
        $classroomIds = $this->findClassroomIdsByCourseId($courseId);
        $members      = $this->findMembersByUserIdAndClassroomIds($userId, $classroomIds);
        return $members;
    }

    public function findUserJoinedClassroomIds($userId)
    {
        return $this->getClassroomMemberDao()->findByUserId($userId);
    }

    public function updateMember($id, $member)
    {
        return $this->getClassroomMemberDao()->update($id, $member);
    }

    public function updateLearndNumByClassroomIdAndUserId($classroomId, $userId)
    {
        $classroomCourses = $this->findCoursesByClassroomId($classroomId);

        $courseIds = ArrayToolkit::column($classroomCourses, 'id');

        $conditions              = array();
        $conditions['courseIds'] = $courseIds;
        $conditions['userId']    = $userId;
        $conditions              = array(
            'userId'    => $userId,
            'courseIds' => $courseIds,
            'status'    => 'finish'
        );
        $userLearnCount = $this->getTaskResultService()->countTaskResults($conditions);

        $fields['lastLearnTime'] = time();
        $fields['learnedNum']    = $userLearnCount;

        $classroomMember = $this->getClassroomMember($classroomId, $userId);
        return $this->updateMember($classroomMember['id'], $fields);
    }

    private function updateStudentNumAndAuditorNum($classroomId)
    {
        $fields = array(
            'studentNum' => $this->getClassroomStudentCount($classroomId),
            'auditorNum' => $this->getClassroomAuditorCount($classroomId)
        );

        return $this->getClassroomDao()->update($classroomId, $fields);
    }

    private function addCourse($id, $courseId)
    {
        $course = $this->getCourseService()->getCourse($courseId);

        $classroomCourse = array(
            'classroomId'    => $id,
            'courseId'       => $courseId,
            'courseSetId'    => $course['courseSetId'],
            'parentCourseId' => $course['parentId']
        );

        $classroomCourse = $this->getClassroomCourseDao()->create($classroomCourse);
        $this->dispatchEvent('classroom.put_course', $classroomCourse);
    }

    protected function _prepareConditions($conditions)
    {
        if (isset($conditions['role'])) {
            $conditions['role'] = "%{$conditions['role']}%";
        }

        if (isset($conditions['roles'])) {
            foreach ($conditions['roles'] as $key => $role) {
                $conditions['roles'][$key] = $role;
            }
        }

        if (isset($conditions['nickname'])) {
            $user                 = $this->getUserService()->getUserByNickname($conditions['nickname']);
            $conditions['userId'] = $user ? $user['id'] : -1;
            unset($conditions['nickname']);
        }

        if (isset($conditions['categoryId'])) {
            $childrenIds               = $this->getCategoryService()->findCategoryChildrenIds($conditions['categoryId']);
            $conditions['categoryIds'] = array_merge(array($conditions['categoryId']), $childrenIds);
            unset($conditions['categoryId']);
        }

        return $conditions;
    }

    /**
     * @return FileService
     */
    public function getFileService()
    {
        return $this->createService('Content:FileService');
    }

    /**
     * @return LogService
     */
    protected function getLogService()
    {
        return $this->createService('System:LogService');
    }

    /**
     * @return ClassroomDao
     */
    protected function getClassroomDao()
    {
        return $this->createDao('Classroom:ClassroomDao');
    }

    /**
     * @return ClassroomMemberDao
     */
    protected function getClassroomMemberDao()
    {
        return $this->createDao('Classroom:ClassroomMemberDao');
    }

    /**
     * @return TagService
     */
    protected function getTagService()
    {
        return $this->createService('Taxonomy:TagService');
    }

    /**
     * @return CourseService
     */
    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    /**
     * @return CourseSetService
     */
    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    /**
     * @return ClassroomCourseDao
     */
    protected function getClassroomCourseDao()
    {
        return $this->createDao('Classroom:ClassroomCourseDao');
    }

    /**
     * @return UserService
     */
    protected function getUserService()
    {
        return $this->createService('User:UserService');
    }

    /**
     * @return OrderService
     */
    protected function getOrderService()
    {
        return $this->createService('Order:OrderService');
    }

    /**
     * @return VipService
     */
    protected function getVipService()
    {
        return $this->createService('Vip:Vip.VipService');
    }

    /**
     * @return CourseNoteDao
     */
    protected function getNoteDao()
    {
        return $this->createDao('Course:CourseNoteDao');
    }

    /**
     * @return StatusService
     */
    protected function getStatusService()
    {
        return $this->createService('User:StatusService');
    }

    /**
     * @return CategoryService
     */
    protected function getCategoryService()
    {
        return $this->createService('Taxonomy:CategoryService');
    }

    /**
     * @return MemberService
     */
    protected function getCourseMemberService()
    {
        return $this->createService('Course:MemberService');
    }

    /**
     * @return TaskResultService
     */
    protected function getTaskResultService()
    {
        return $this->createService('Task:TaskResultService');
    }
}

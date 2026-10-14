import { ref } from 'vue'
export function useGridDragAndDrop(isEditing, rooms, timeslots, topics, updateSchedule, props, defaultValues) {
  const draggedRow = ref(null)
  const dragOverRow = ref(null)
  const draggedCol = ref(null)
  const dragOverCol = ref(null)
  const draggedTopicIndex = ref(null)
  const dragOverTopicIndex = ref(null)
  const isDraggingSession = ref(false)
  const dragOverSession = ref(null)

  const onDragStart = (type, id, event) => {
    if (!isEditing.value) return
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('type', type)
    event.dataTransfer.setData('data', JSON.stringify(id))
    
    if (type === 'row') {
      draggedRow.value = id
    } else if (type === 'column') {
      draggedCol.value = id
    } else if (type === 'topic') {
      draggedTopicIndex.value = id
    } else if (type === 'session') {
      isDraggingSession.value = true
    }
  }

  const onDragEnd = () => {
    draggedRow.value = null
    dragOverRow.value = null
    draggedCol.value = null
    dragOverCol.value = null
    draggedTopicIndex.value = null
    dragOverTopicIndex.value = null
    isDraggingSession.value = false
    dragOverSession.value = null
  }

  const onDragOver = (type, id, event) => {
    if (!isEditing.value) return
    event.preventDefault()
    event.dataTransfer.dropEffect = 'move'

    if (type === 'row' && draggedRow.value !== null) {
      dragOverRow.value = id
    } else if (type === 'column' && draggedCol.value !== null) {
      dragOverCol.value = id
    } else if (type === 'topic' && draggedTopicIndex.value !== null) {
      dragOverTopicIndex.value = id
    } else if (type === 'session' && (isDraggingSession.value || draggedTopicIndex.value !== null)) {
      dragOverSession.value = id
    }
  }

  const onDragLeave = () => {
    dragOverRow.value = null
    dragOverCol.value = null
    dragOverTopicIndex.value = null
    dragOverSession.value = null
  }

  const onDrop = (type, targetData, event) => {
    if (!isEditing.value) return
    event.preventDefault()

    const sourceType = event.dataTransfer.getData('type');
    const sourceData = JSON.parse(event.dataTransfer.getData('data'));

    if (type === 'topic' && sourceType === 'topic') {
      swapTopics(sourceData, targetData);
      onDragEnd();
      return;
    }

    if (type === 'session' && sourceType === 'topic') {
      onDragEnd();
      const topic = topics.value[sourceData];
      const newValue = topic.name;
      const { timeslot, room } = targetData;
      
      let newSessions = [...(props.schedule.value.data.sessions || [])];
      const sessionIdx = newSessions.findIndex(s => s.timeslot === timeslot && s.room === room);
      if (sessionIdx > -1) {
        newSessions[sessionIdx] = { ...newSessions[sessionIdx], topic: newValue };
      } else {
        newSessions.push({
          timeslot, room,
          title: defaultValues.title,
          speakers: '',
          format: '',
          topic: newValue
        });
      }
      updateSchedule({ sessions: newSessions });
      return;
    }
    onDragEnd();
    if (sourceType !== type) return;

    if (type === 'session') {
      swapSessions(sourceData, targetData);
    } else if (type === 'row') {
      swapRows(sourceData, targetData);
    } else if (type === 'column') {
      swapColumns(sourceData, targetData);
    }
  }

  const swapItems = (array, i, j) => {
    [array[i], array[j]] = [array[j], array[i]]
  }

  const swapTopics = (sourceIdx, targetIdx) => {
    if (sourceIdx === targetIdx) return;
    let newTopics = [...(props.schedule.value.data.topics || [])];
    newTopics = topics.value.map((t) => {
      const existing = newTopics.find(et => et.name === t.name);
      return { ...existing };
    });
    swapItems(newTopics, sourceIdx, targetIdx);
    updateSchedule({ topics: newTopics });
  };

  const swapSessions = (source, target) => {
    if (source.timeslot === target.timeslot && source.room === target.room) return;
    let newSessions = [...(props.schedule.value.data.sessions || [])];
    const sourceIdx = newSessions.findIndex(s => s.timeslot === source.timeslot && s.room === source.room);
    const targetIdx = newSessions.findIndex(s => s.timeslot === target.timeslot && s.room === target.room);
    if (sourceIdx > -1 && targetIdx > -1) {
      const sourceSession = { ...newSessions[sourceIdx], timeslot: target.timeslot, room: target.room };
      const targetSession = { ...newSessions[targetIdx], timeslot: source.timeslot, room: source.room };
      newSessions[sourceIdx] = sourceSession;
      newSessions[targetIdx] = targetSession;
    } else if (sourceIdx > -1) {
      newSessions[sourceIdx] = { ...newSessions[sourceIdx], timeslot: target.timeslot, room: target.room };
    } else if (targetIdx > -1) {
      newSessions[targetIdx] = { ...newSessions[targetIdx], timeslot: source.timeslot, room: source.room };
    }
    updateSchedule({ sessions: newSessions });
  };

  const swapRows = (sourceIdx, targetIdx) => {
    if (sourceIdx === targetIdx) return;
    const newTimeslots = [...timeslots.value];
    swapItems(newTimeslots, sourceIdx, targetIdx);
    updateSchedule({ timeslots: newTimeslots });
  };

  const swapColumns = (sourceIdx, targetIdx) => {
    if (sourceIdx === targetIdx) return;
    const newRooms = [...rooms.value];
    swapItems(newRooms, sourceIdx, targetIdx);
    updateSchedule({ rooms: newRooms });
  };

  return {
    draggedRow,
    dragOverRow,
    draggedCol,
    dragOverCol,
    draggedTopicIndex,
    dragOverTopicIndex,
    isDraggingSession,
    dragOverSession,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDragLeave,
    onDrop
  }
}

jQuery(document).ready(function(){
    // Initialize the categories field as usual (AJAX for searching)
    jQuery('#meal_categories').select2({
        placeholder: catering_backend_ajax.i18n.categoryPlaceholder,
        ajax: {
            url: catering_backend_ajax.ajaxUrl,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    action: 'catering_search_categories',
                    q: params.term
                };
            },
            processResults: function(data) {
                return { results: data };
            },
            cache: true
        },
        minimumInputLength: 0,
        width: 'resolve'
    });

    // Initialize the tags field similarly
    jQuery('#meal_tags').select2({
        placeholder: catering_backend_ajax.i18n.tagPlaceholder,
        ajax: {
            url: catering_backend_ajax.ajaxUrl,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    action: 'catering_search_tags',
                    q: params.term
                };
            },
            processResults: function(data) {
                return { results: data };
            },
            cache: true
        },
        minimumInputLength: 0,
        width: 'resolve'
    });



});

function bulkEditTerms(actionVal,selectedMeals){

      // 1) Decide whether we're dealing with categories or tags
      var termType = (actionVal === 'edit_category') ? 'category' : 'tag';

      // 2) AJAX call to fetch all terms of this type
      //    We'll define a custom action like "get_all_categories" or "get_all_tags"
      //    But let's unify it with a param: "get_all_terms"
      jQuery.ajax({
        url: catering_backend_ajax.ajaxUrl,
        type: 'POST',
        data: {
          action: 'get_all_terms',        // your unified endpoint
          termType: termType,            // 'category' or 'tag'
        },
        success: function(resp){
          if (resp.success) {
            // resp.data is presumably an array of { id, title }
            showEditTermsRow(actionVal, selectedMeals, resp.data );
          } else {
            alert('Failed to load terms: ' + resp.data);
          }
        },
        error: function(xhr, status, error){
          alert('AJAX error: ' + error);
        }
      });

}

// A function to build and show the dynamic row
function showEditTermsRow(actionVal, mealIDs, allTerms){
  // remove any existing dynamic row
  jQuery('#edit-terms-row').remove();

  var newRowHtml = '<tr id="edit-terms-row"><td colspan="5">'
     + buildEditTermsUI(actionVal, mealIDs, allTerms)
     + '</td></tr>';

  // Insert below table header
  jQuery('table.widefat thead').after(newRowHtml);
}

// Build the actual UI with two sections: "Set Terms" / "Add Terms"
function buildEditTermsUI(actionVal, mealIDs, allTerms){
  var title = (actionVal==='edit_category') ? 'Edit Category' : 'Edit Tag';
  var setTermText = (actionVal==='edit_category') ? 'Set Categories' : 'Set Tags';
  var addTermText = (actionVal==='edit_category') ? 'Add Categories' : 'Add Tags';
  var html = '<div style="margin: 10px 0;"><strong>' + title + '</strong></div>';

  // Section A: Set Terms
  html += '<div>';
  html += '<h4>' + setTermText + ' (remove old, replace with these):</h4>';
  html += '<div id="set-terms-checkboxes">';

  allTerms.forEach(function(t){
    html += '<label style="margin-right:10px;">'
          + '<input type="checkbox" name="set_terms[]" value="'+ t.id +'"> '
          + t.title
          + '</label>';
  });
  html += '</div>';
  html += '<button type="button" class="button" onclick="applySetTerms(\''+actionVal+'\', ['+ mealIDs.join(',') +'])">'
        + 'Apply'
        + '</button>';
  html += '</div>';

  // Section B: Add Terms
  html += '<div style="margin-top:20px;">';
  html += '<h4>' + addTermText + ' (keep old, add these new ones):</h4>';
  html += '<div id="add-terms-checkboxes">';

  allTerms.forEach(function(t){
    html += '<label style="margin-right:10px;">'
          + '<input type="checkbox" name="add_terms[]" value="'+ t.id +'"> '
          + t.title
          + '</label>';
  });
  html += '</div>';
  html += '<button type="button" class="button" onclick="applyAddTerms(\''+actionVal+'\', ['+ mealIDs.join(',') +'])">'
        + 'Apply'
        + '</button>';
  html += '</div>';

  return html;
}

function applySetTerms(actionVal, mealIDs){
  var chosen = [];
  jQuery('#set-terms-checkboxes input[type=checkbox]:checked').each(function(){
    chosen.push(jQuery(this).val());
  });
  if (chosen.length===0){
    alert('Please select at least one term');
    return;
  }
  var sure = confirm('Are you sure to perform this action?');
  if ( ! sure ) {
      e.preventDefault();
      return false;
  }

  // We do another AJAX call to handle the DB update
  var termType = (actionVal==='edit_category') ? 'category' : 'tag';
  jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
      action: 'bulk_edit_set_terms',
      termType: termType,
      mealIDs: mealIDs,
      chosenTermIDs: chosen,
    },
    success: function(resp){
      if(resp.success){
        location.reload();
      } else {
        alert('Error: ' + resp.data);
      }
    },
    error: function(xhr, status, error){
      alert('AJAX error: '+ error);
    }
  });
}

function applyAddTerms(actionVal, mealIDs){
  var chosen = [];
  jQuery('#add-terms-checkboxes input[type=checkbox]:checked').each(function(){
    chosen.push(jQuery(this).val());
  });
  if (chosen.length===0){
    alert('Please select at least one term.", "catering-booking-and-scheduling');
    return;
  }
  var sure = confirm('Are you sure to perform this action?');
  if ( ! sure ) {
      e.preventDefault();
      return false;
  }

  var termType = (actionVal==='edit_category') ? 'category' : 'tag';
  jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
      action: 'bulk_edit_add_terms',
      termType: termType,
      mealIDs: mealIDs,
      chosenTermIDs: chosen,
    },
    success: function(resp){
      if(resp.success){
        location.reload();
      } else {
        alert('Error: ' + resp.data);
      }
    },
    error: function(xhr, status, error){
      alert('AJAX error: '+ error);
    }
  });
}


























//
